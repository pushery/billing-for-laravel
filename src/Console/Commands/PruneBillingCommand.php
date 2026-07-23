<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Support\OwnerScopedTables;

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
 * (§147 AO, §14b UStG — about ten years), and they go once it does not.
 */
final class PruneBillingCommand extends Command
{
    /**
     * The column that carries a retained record's ISSUE date per table — the date the §147(4) retention
     * clock counts from. A table not listed falls back to created_at.
     *
     * @var array<string,string>
     */
    private const array RETENTION_ISSUE_COLUMN = [
        'billing_invoices' => 'issued_at',
    ];

    protected $signature = 'billing:prune {--dry-run : Report what would be pruned, delete nothing}';

    protected $description = 'Age out stored webhook payloads and financial records past their retention';

    public function handle(Repository $config): int
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

        $financialCount = 0;

        foreach (OwnerScopedTables::RETAINED as $table) {
            $issueColumn = self::RETENTION_ISSUE_COLUMN[$table] ?? 'created_at';

            $rows = DB::table($table)
                ->whereNotNull('owner_erased_at')
                // COALESCE so a record with no explicit issue date falls back to when it was created — a
                // null issue date must never read as "infinitely old" and prune early. The cutoff is bound as
                // a datetime STRING: a raw binding does not go through the datetime caster, so a Carbon here
                // would compare as an unusable value and quietly match nothing.
                ->whereRaw("COALESCE({$issueColumn}, created_at) < ?", [$financialCutoff->toDateTimeString()]);

            $financialCount += $dryRun ? $rows->count() : $rows->delete();
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

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info("{$verb} {$payloadCount} stored webhook payload(s), {$financialCount} retained financial record(s) and {$auditCount} audit event(s).");

        return self::SUCCESS;
    }

    private function days(Repository $config, string $key, int $default): int
    {
        $days = $config->get('billing.retention.'.$key, $default);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
