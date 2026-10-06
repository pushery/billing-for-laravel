<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\RetentionClock;
use Pushery\Billing\Enums\RetentionExecutor;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Exceptions\RetentionHoldUnavailable;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\WebhookEffectRun;
use Pushery\Billing\Support\OwnerScopedTables;
use Pushery\Billing\Support\RetentionHoldGate;
use Pushery\Billing\Support\RetentionMatrix;
use Pushery\Billing\Support\StoredDocumentFiles;
use Pushery\Billing\Support\SubjectScopedRecords;
use Pushery\Billing\ValueObjects\RetentionRule;

/**
 * The retention clock. Personal data the package no longer needs is not data it may keep (GDPR Art. 5(1)(e)
 * — storage limitation), and "we never got round to deleting it" is not a retention policy.
 *
 * Two things age out:
 *
 * The stored WEBHOOK PAYLOADS. They are the package's largest store of personal data — a Stripe event
 * carries the customer's email, name, billing address and card last four — and they are kept for one reason
 * only: so a failed effect can be re-driven from what the provider already sent. Once the provider itself
 * has stopped redelivering (Stripe gives up after about three days), that reason has expired. Ninety days
 * is a generous default. The delivery ROW stays: it is the dedup that keeps a redelivery from being
 * processed twice, and it holds no personal data once the payload is gone.
 *
 * The RETAINED financial rows of erased owners. They outlive the erasure because the law requires them
 * (§14b Abs. 1 UStG n. F. — EIGHT years, the shipped default of 2920 days), and they go once it does not.
 *
 * Eight, not ten, and the difference is the whole point rather than a rounding of it. The ten-year window
 * (§147 AO, §257 HGB) is a SEPARATE obligation covering books and posting batches — it is `audit_days`
 * below, deliberately a different number for a different record class. Retaining an invoice to the book
 * window would keep the buyer's name and address two years past the obligation that justifies holding
 * them at all, which is a storage-limitation breach (GDPR Art. 5(1)(e)), not a safe margin.
 *
 * The shipped config and `RetentionFloorGuard` both say this; this docblock said "about ten years" and
 * was the only place that disagreed — describing the unlawful variant as though it were what runs.
 */
final class PruneBillingCommand extends Command
{
    /** The filings of the seller-reporting records, which leave a reporting period at a time. */
    private const string FILINGS = 'billing_reporting_filings';

    protected $signature = 'billing:prune {--dry-run : Report what would be pruned, delete nothing}';

    protected $description = 'Age out stored webhook payloads and financial records past their retention';

    /** Set when the host's hold seam could not answer; that path pruned nothing and the run reports it. */
    private bool $holdUnanswered = false;

    public function handle(
        Repository $config,
        SubjectScopedRecords $records,
        RetentionMatrix $matrix,
        RetentionHoldGate $holds,
        StoredDocumentFiles $files,
    ): int {
        $dryRun = $this->option('dry-run') === true;

        $payloadCutoff = Carbon::now()->subDays($this->days($config, 'webhook_payload_days', 90));

        $payloads = DB::table(OwnerScopedTables::SCRUBBED)
            ->whereNotNull('payload')
            ->where('status', WebhookEventState::Handled->value)
            ->where('created_at', '<=', $payloadCutoff)
            ->whereNotIn('id', WebhookEffectRun::model()::query()
                ->select('delivery_id')
                ->whereNotNull('delivery_id')
                ->where('status', '!=', WebhookEventState::Handled->value));

        // A delivery whose effects are still owed keeps its payload however old it is: dropping it would
        // throw away the only copy of work the package knows it has not finished. A handled delivery is one
        // whose effects were queued, not one whose effects ran, so what it still owes is read from its effect
        // runs: one that is pending or failed keeps the payload a replay needs. The subquery leaves out runs
        // without a delivery, because a single NULL in a NOT IN list would keep every payload.
        //
        // And one the host holds keeps it too. Nulling a payload is a destruction like any other here — the
        // row survives, but the thing a preservation order was about does not.
        //
        // Stamped, so that a later redelivery of the same event leaves the payload pruned. The ledger refills
        // only a payload that was never kept, and without the stamp a pruned one looks exactly like that.
        //
        // The error recorded beside the payload goes with it, as it does in an erasure: it can quote the payload.
        $payloadCount = $this->guarded(
            OwnerScopedTables::SCRUBBED,
            $payloads,
            $holds,
            static fn (Builder $q): int => $dryRun ? $q->count() : $q->update(['payload' => null, 'last_error' => null, 'payload_removed_at' => Carbon::now()]),
        );

        // §147 Abs. 4 AO: the retention clock starts at the END of the year the document was issued, so a
        // record is kept for the floor counted from that year end — NOT from the raw issue instant. Anchoring
        // to the year start of (now − floor) is what implements it: an invoice issued in March of a year and
        // one issued that December are kept the same length (to the following year boundary), instead of the
        // March one being deleted nine months too early. A record is pruned only once BOTH hold: its owner was
        // erased, and the statutory window from its issue year has passed.
        $financialFloor = $this->days($config, 'erased_financial_days', 2920);
        $financialCutoff = $this->yearEndCutoff($financialFloor);

        // EVERY axis, not just the buyer's. The statutory window is a property of the document, not of whose
        // name is on it: a merchant's payout statement ages out under the same rule as a buyer's invoice, and
        // iterating the axes means a new one is covered the day it is added rather than the day somebody
        // remembers this loop exists.
        $financialCount = 0;

        // Which column dates a record comes from the matrix, not from a copy here: with a copy, a consumer
        // asking `issueColumnFor()` and the package's own pruner could answer differently.
        $issueColumns = $matrix->issueColumns();

        // A retained table whose own rule asks for longer keeps that window. Place evidence keeps ten years where an
        // erased person's documents keep eight, and one cutoff for the whole axis deleted the evidence two years
        // before the return it supports stopped being examinable. Counted from the year end like the documents, and
        // never shorter than their floor: a rule here can lengthen a statutory window, not shorten one.
        $cutoffs = [];

        foreach ($matrix->rules() as $rule) {
            if ($rule->days !== null) {
                $cutoffs[$rule->object] = $this->yearEndCutoff(max($rule->days, $financialFloor))->toDateTimeString();
            }
        }

        foreach (OwnerScopedTables::axes() as $axis) {
            try {
                $financialCount += $records->pruneExpired(
                    $axis,
                    $financialCutoff->toDateTimeString(),
                    $issueColumns,
                    $dryRun,
                    $holds,
                    $cutoffs,
                    $files,
                );
            } catch (RetentionHoldUnavailable $e) {
                $this->reportUnansweredHold($e);
            }
        }

        // The audit ledger. GDPR storage limitation (Art. 5(1)(e)) says personal data is not kept longer
        // than needed; bookkeeping law (§257 HGB, §147 AO) says booking records ARE kept for years. The
        // default window is the longer, book-keeping one — check it against your obligations. Deleted by
        // query: the append-only guard reads what is done through a model, and a sweep over years of rows
        // loads none.
        // The audit/book window keeps its ten-year default and its raw age cutoff — a different record class
        // (book-keeping, not invoices) with a different statute (§257 HGB / §147 AO).
        $auditCutoff = Carbon::now()->subDays($this->days($config, 'audit_days', 3650));
        $expiredAudit = BillingEvent::model()::query()->where('created_at', '<=', $auditCutoff);

        $auditCount = $this->guarded(
            'billing_events',
            $expiredAudit->toBase(),
            $holds,
            static fn (Builder $q): int => $dryRun ? $q->count() : $q->delete(),
        );

        // The filings of the seller-reporting records, a reporting period at a time. They go ahead of the time
        // pruner, so the records they filed are its to remove in the same run.
        $leavingFilings = $this->pruneFilings($matrix->ruleFor(self::FILINGS), $holds, $dryRun);
        $filingCount = count($leavingFilings);
        $leaving = [self::FILINGS => $leavingFilings];

        // The rules nobody was carrying out. A period-scoped document — a produced tax return, a produced
        // seller-reporting file — names a PERIOD rather than a person, so no erasure axis can reach it: there
        // is no subject to erase. The matrix declared a window for both and the dry run printed it as "the
        // record of what this run enforces", while the rows sat there forever.
        //
        // Driven off the rule's own stated executor rather than off its shape. The shape cannot decide it:
        // `billing_place_evidence` carries the same Delete/CreatedAt signature and belongs to an erasure
        // axis, so a loop that inferred responsibility from the signature would delete it a second time and
        // behind the axis's back.
        $timePrunedCount = 0;

        foreach ($matrix->rules() as $rule) {
            if ($rule->executor !== RetentionExecutor::TimePruner) {
                continue;
            }

            if ($rule->days === null) {
                continue;
            }

            $expired = DB::table($rule->object);

            if ($rule->clock === RetentionClock::IssueYearEnd) {
                // The window counts from the end of the year the record was made in, as it does for the
                // documents of an erased person above: a return produced in January is kept as long as
                // one produced that December, and neither goes before its year's window has closed.
                $issueColumn = $issueColumns[$rule->object] ?? 'created_at';

                $expired->whereRaw("COALESCE({$issueColumn}, created_at) < ?", [$this->yearEndCutoff($rule->days)->toDateTimeString()]);
            } else {
                $expired->where('created_at', '<=', Carbon::now()->subDays($rule->days));
            }

            // A row another record still points at has not expired, however old it is. Left out of the
            // query rather than left to the foreign key: one refused row fails the whole statement, and
            // with it every table that comes after. A record that leaves in this run points at nothing any
            // more. It is set aside by id, so a dry run, which removes nothing, counts what a run frees.
            foreach ($rule->referencedBy as $table => $column) {
                $leaves = $leaving[$table] ?? [];

                $expired->whereNotExists(static function (Builder $q) use ($table, $column, $rule, $leaves): void {
                    $q->selectRaw('1')
                        ->from($table)
                        ->whereColumn($table.'.'.$column, $rule->object.'.id');

                    if ($leaves !== []) {
                        $q->whereNotIn($table.'.id', $leaves);
                    }
                });
            }

            // A produced document may also have been written to a disk. Its file goes after its row, and only
            // when no remaining row names it; StoredDocumentFiles says why both halves of that matter.
            $timePrunedCount += $this->guarded(
                $rule->object,
                $expired,
                $holds,
                static fn (Builder $q): int => $dryRun ? $files->countWithFiles($rule->object, $q) : $files->deleteWithFiles($rule->object, $q),
            );
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info("{$verb} {$payloadCount} stored webhook payload(s), {$financialCount} retained financial record(s), {$auditCount} audit event(s), {$filingCount} reporting filing(s) and {$timePrunedCount} expired export document(s).");

        if ($dryRun && $files->pending() > 0) {
            $this->components->info("Would remove {$files->pending()} stored document file(s) with them.");
        }

        if (! $dryRun && $files->removed() + $files->missing() + $files->failed() > 0) {
            $this->components->info("Removed {$files->removed()} stored document file(s) with them; {$files->missing()} had already gone.");
        }

        // A file its disk did not remove is a retention duty left undone, and its row, the one thing that named
        // it, is gone. Each one is logged with its disk and path, and the run fails so the scheduler says so.
        if ($files->failed() > 0) {
            $this->components->error("{$files->failed()} stored document file(s) could not be removed; the log names each disk and path.");
        }

        // A dry run doubles as the evidence an audit asks for, so it states the rules rather than only the
        // totals: what is held, for how long, counted from when, on whose authority. A number without its
        // reason is a number somebody eventually shortens because it looks arbitrary.
        if ($dryRun) {
            $this->reportRules($matrix);
        }

        // A record that must never have been kept is not pruned here — it is REPORTED. Its duty is
        // discharged where it is processed, so a survivor means a code path did not discharge it, and
        // quietly cleaning up would hide the defect and leave it happening.
        $survivors = $this->immediateSurvivors($matrix);

        if ($survivors !== []) {
            foreach ($survivors as $object => $count) {
                $this->components->error("{$count} row(s) still hold [{$object}], which must be discarded where it is processed.");
            }

            return self::FAILURE;
        }

        // A retention duty that could not be carried out is a failed run, not a quiet one. The scheduler is
        // the only thing watching, and it watches the exit status.
        return $this->holdUnanswered || $files->failed() > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one deletion, minus whatever the host holds.
     *
     * The paths that delete by one plain query go through here, and the two that need more (an erasure
     * axis, and the filings of a reporting period) ask the same gate themselves. So no path answers the hold
     * question differently, and a path added later that asks nothing visibly did not ask. When the seam
     * cannot answer, this deletes NOTHING from that table and marks the run failed: falling back to "nothing
     * is held" would destroy exactly the records the seam exists to protect, at the moment it had lost the
     * ability to object.
     *
     * @param  callable(Builder): int  $act
     */
    private function guarded(string $recordType, Builder $query, RetentionHoldGate $holds, callable $act): int
    {
        try {
            $held = $holds->heldIn($recordType, $query);
        } catch (RetentionHoldUnavailable $e) {
            $this->reportUnansweredHold($e);

            return 0;
        }

        if ($held !== []) {
            RetentionHoldGate::whereKeys($query, 'id', $held, not: true);
        }

        return $act($query);
    }

    private function reportUnansweredHold(RetentionHoldUnavailable $e): void
    {
        $this->holdUnanswered = true;

        $this->components->error($e->getMessage());
    }

    /**
     * Remove the filings of every reporting period that has had its window, and name the ones that leave.
     *
     * A period leaves whole or not at all. A correction names the filing it corrects, every such reference
     * stays inside its period, and a filing a correction names is kept for as long as the correction is. So
     * the youngest filing of a period decides: once it has had the window, so has every filing before it,
     * and none of them is left pointing at nothing. The window counts from the end of the year a filing was
     * recorded in.
     *
     * A filing the host holds keeps its whole period, because what is left of a chain without one of its
     * links is no record of the period.
     *
     * The periods are read before anything is deleted, since MySQL refuses a deletion that reads the table it
     * deletes from. The filings then go newest first, one statement per correction level: the key from a
     * correction to the filing it corrects restricts the deletion, and SQLite and MySQL check it row by row.
     *
     * @return array<mixed> the ids of the filings that leave in this run, which a dry run names and keeps
     */
    private function pruneFilings(?RetentionRule $rule, RetentionHoldGate $holds, bool $dryRun): array
    {
        if (! $rule instanceof RetentionRule || $rule->days === null) {
            return [];
        }

        $cutoff = $this->yearEndCutoff($rule->days)->toDateTimeString();

        // Every filing of a period that has no filing left inside the window.
        $expired = DB::table(self::FILINGS.' as filing')
            ->whereNotExists(static fn (Builder $young): Builder => $young
                ->selectRaw('1')
                ->from(self::FILINGS.' as young')
                ->whereColumn('young.period_year', 'filing.period_year')
                ->whereColumn('young.currency', 'filing.currency')
                ->where('young.created_at', '>=', $cutoff));

        try {
            $held = $holds->heldIn(self::FILINGS, $expired);
        } catch (RetentionHoldUnavailable $e) {
            $this->reportUnansweredHold($e);

            return [];
        }

        $leaving = $expired
            ->whereNotExists(static fn (Builder $kept): Builder => $kept
                ->selectRaw('1')
                ->from(self::FILINGS.' as kept')
                ->whereColumn('kept.period_year', 'filing.period_year')
                ->whereColumn('kept.currency', 'filing.currency')
                ->where(static fn (Builder $ids): Builder => RetentionHoldGate::whereKeys($ids, 'kept.id', $held)))
            ->pluck('filing.id')
            ->all();

        if ($dryRun || $leaving === []) {
            return $leaving;
        }

        DB::transaction(static function () use ($leaving): void {
            $levels = DB::table(self::FILINGS)
                ->whereIn('id', $leaving)
                ->distinct()
                ->orderByDesc('correction_sequence')
                ->pluck('correction_sequence');

            foreach ($levels as $level) {
                DB::table(self::FILINGS)->whereIn('id', $leaving)->where('correction_sequence', $level)->delete();
            }
        });

        return $leaving;
    }

    /** The rule set, as the record of what this run enforces. */
    private function reportRules(RetentionMatrix $matrix): void
    {
        $this->newLine();

        foreach ($matrix->rules() as $rule) {
            $window = $rule->days === null ? '—' : $rule->days.' days';

            $this->components->twoColumnDetail(
                "{$rule->object}  <fg=gray>{$rule->action->value} · {$rule->clock->value}</>",
                "{$window}  <fg=gray>".Lang::get($rule->basisKey).'</>',
            );
        }
    }

    /**
     * Rows still holding something that carries no reason to be held.
     *
     * @return array<string, int>
     */
    private function immediateSurvivors(RetentionMatrix $matrix): array
    {
        $found = [];

        foreach ($matrix->rules() as $rule) {
            if (! $rule->isImmediate()) {
                continue;
            }
            if ($rule->columns === []) {
                continue;
            }
            $query = DB::table($rule->object);

            $query->where(static function (Builder $inner) use ($rule): void {
                foreach ($rule->columns as $column) {
                    $inner->orWhereNotNull($column);
                }
            });

            $count = $query->count();

            if ($count > 0) {
                $found[$rule->object] = $count;
            }
        }

        return $found;
    }

    /**
     * The first day of the first year that is still inside a window counted from a year's end.
     *
     * A record made before this day has been kept for the whole window. The statutory windows are a number
     * of YEARS from the end of a year, and configuration states them in days. Counted as days they close
     * early: eight years hold two leap days, so 2920 days from the end of a year are over on 30 December
     * of the eighth year, two days before the window is. The whole years are therefore counted as years,
     * and only what is left over as days.
     */
    private function yearEndCutoff(int $days): Carbon
    {
        return Carbon::now()->subDays($days % 365)->subYears(intdiv($days, 365))->startOfYear();
    }

    private function days(Repository $config, string $key, int $default): int
    {
        $days = $config->get('billing.retention.'.$key, $default);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
