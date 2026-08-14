<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\RetentionExecutor;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Support\OwnerScopedTables;
use Pushery\Billing\Support\RetentionMatrix;
use Pushery\Billing\Support\SubjectScopedRecords;

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
    protected $signature = 'billing:prune {--dry-run : Report what would be pruned, delete nothing}';

    protected $description = 'Age out stored webhook payloads and financial records past their retention';

    public function handle(Repository $config, SubjectScopedRecords $records, RetentionMatrix $matrix): int
    {
        $dryRun = $this->option('dry-run') === true;

        $payloadCutoff = Carbon::now()->subDays($this->days($config, 'webhook_payload_days', 90));

        $payloads = DB::table(OwnerScopedTables::SCRUBBED)
            ->whereNotNull('payload')
            ->where('status', WebhookEventState::Handled->value)
            ->where('created_at', '<=', $payloadCutoff);

        // A delivery whose effects are still owed keeps its payload however old it is: dropping it would
        // throw away the only copy of work the package knows it has not finished.
        $payloadCount = $dryRun ? $payloads->count() : $payloads->update(['payload' => null]);

        // §147 Abs. 4 AO: the retention clock starts at the END of the year the document was issued, so a
        // record is kept for the floor counted from that year end — NOT from the raw issue instant. Anchoring
        // to the year start of (now − floor) is what implements it: an invoice issued in March of a year and
        // one issued that December are kept the same length (to the following year boundary), instead of the
        // March one being deleted nine months too early. A record is pruned only once BOTH hold: its owner was
        // erased, and the statutory window from its issue year has passed.
        $financialFloor = $this->days($config, 'erased_financial_days', 2920);
        $financialCutoff = Carbon::now()->subDays($financialFloor)->startOfYear();

        // EVERY axis, not just the buyer's. The statutory window is a property of the document, not of whose
        // name is on it: a merchant's payout statement ages out under the same rule as a buyer's invoice, and
        // iterating the axes means a new one is covered the day it is added rather than the day somebody
        // remembers this loop exists.
        $financialCount = 0;

        // Which column dates a record comes from the matrix, not from a copy here. This command used to
        // carry its own — the same table-to-column list under a different name, in the method that already
        // receives the matrix. Nothing was wrong while both held one identical entry; what was wrong is that
        // a consumer asking `issueColumnFor()` and the package's own pruner could ever answer differently.
        $issueColumns = $matrix->issueColumns();

        foreach (OwnerScopedTables::axes() as $axis) {
            $financialCount += $records->pruneExpired(
                $axis,
                $financialCutoff->toDateTimeString(),
                $issueColumns,
                $dryRun,
            );
        }

        // The audit ledger. GDPR storage limitation (Art. 5(1)(e)) says personal data is not kept longer
        // than needed; bookkeeping law (§257 HGB, §147 AO) says booking records ARE kept for years. The
        // default window is the longer, book-keeping one — check it against your obligations. Deleted through
        // the append-only guard's purge, the only sanctioned way an audit row leaves.
        // The audit/book window keeps its ten-year default and its raw age cutoff — a different record class
        // (book-keeping, not invoices) with a different statute (§257 HGB / §147 AO).
        $auditCutoff = Carbon::now()->subDays($this->days($config, 'audit_days', 3650));
        $expiredAudit = BillingEvent::query()->where('created_at', '<=', $auditCutoff);

        $auditCount = $dryRun
            ? $expiredAudit->count()
            : BillingEvent::purging(static function () use ($expiredAudit): int {
                $deleted = $expiredAudit->delete();

                return is_int($deleted) ? $deleted : 0;
            });

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

            $expired = DB::table($rule->object)
                ->where('created_at', '<=', Carbon::now()->subDays($rule->days));

            $timePrunedCount += $dryRun ? $expired->count() : $expired->delete();
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info("{$verb} {$payloadCount} stored webhook payload(s), {$financialCount} retained financial record(s), {$auditCount} audit event(s) and {$timePrunedCount} expired export document(s).");

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

        return self::SUCCESS;
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

    private function days(Repository $config, string $key, int $default): int
    {
        $days = $config->get('billing.retention.'.$key, $default);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
