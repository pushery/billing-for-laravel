<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillableAccountDeleting;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\CreditBalance;
use Pushery\Billing\ValueObjects\ErasureReport;

/**
 * Erases an owner's billing data — the package's answer to a right-to-erasure request (GDPR Art. 17).
 *
 * WHAT IT DOES NOT DO IS THE IMPORTANT PART. It does not delete the invoices. A valid invoice must carry
 * the buyer's name and address (§14 UStG), and invoices must be kept for years (§147 AO, §14b UStG); the
 * right to erasure yields to a legal retention obligation (Art. 17(3)(b)). So the financial record is
 * UNLINKED from the owner and kept, and the retention clock (`billing:prune`) removes it once the law stops
 * requiring it. An implementation that cascaded the invoices away would destroy tax records.
 *
 * Everything else goes. The owner's own provider API keys go FIRST and unconditionally — live payment
 * credentials sitting in a database with no owner are a security incident, not merely a compliance one. The
 * stored webhook payloads are scrubbed too: they carry the customer's email, name, billing address and card
 * last four, and a right to erasure that cannot reach the data is not a right to erasure.
 *
 * A credit balance is money owed to the customer, so it is recorded to the audit ledger before it goes —
 * otherwise the erase would silently destroy a liability.
 *
 * It all happens in ONE transaction: a half-erased owner is worse than an un-erased one, because nobody
 * would know which half.
 */
final readonly class BillingEraser
{
    public function __construct(
        private BillingEventLog $log,
        private CustomerRegistry $customers,
    ) {}

    public function erase(Model $owner): ErasureReport
    {
        // Stop live billing FIRST, before anything is erased: an owner whose data is gone but whose
        // subscription keeps charging is a money leak AND a compliance breach (you cannot bill someone you
        // erased). Dispatched — not called directly — so the same listener serves an app's own delete flow,
        // and OUTSIDE the erase transaction below so the provider API call never runs inside the DB tx. The
        // listener degrades on a transient provider failure (logs + continues), so the erase is never orphaned.
        event(new BillableAccountDeleting($owner));

        $credit = $this->outstandingCredit($owner);

        $report = DB::transaction(function () use ($owner, $credit): ErasureReport {
            $purged = [];

            // Child tables key on their parent row, not on the owner, so the loop below cannot see them —
            // and they must go FIRST, while the parent rows still exist to join through. Why this is not
            // left to the foreign key is recorded on the map itself (OwnerScopedTables::CASCADED).
            foreach (OwnerScopedTables::CASCADED as $table => $link) {
                $purged[$table] = DB::table($table)
                    ->whereIn($link['foreign_key'], $this->owned($link['parent'], $owner)->select('id'))
                    ->delete();
            }

            foreach (OwnerScopedTables::PURGED as $table) {
                $purged[$table] = $this->owned($table, $owner)->delete();
            }

            // The delivery record stays — it is what makes a failed effect replayable, and it is the
            // package's own account of what the provider sent. Only the personal data inside it goes.
            $purged[OwnerScopedTables::SCRUBBED] = $this->owned(OwnerScopedTables::SCRUBBED, $owner)
                ->whereNotNull('payload')
                ->update(['payload' => null]);

            $retained = [];

            foreach (OwnerScopedTables::RETAINED as $table) {
                $retained[$table] = $this->owned($table, $owner)->update([
                    'owner_type' => null,
                    'owner_id' => null,
                    'owner_erased_at' => Carbon::now(),
                ]);
            }

            // The owner's own audit rows go with them. Then ONE row records that the erasure happened:
            // accountability (Art. 5(2)) means being able to show it was done — and that record must not
            // itself become a fresh copy of the personal data, so it carries the morph class and nothing
            // that could identify the person.
            // Audit rows are append-only; an erasure is one of the two authorized ways they may be deleted.
            // Both the subject (what happened to the owner) and the actor (what the owner did) are theirs.
            BillingEvent::purging(function () use ($owner): void {
                BillingEvent::query()
                    ->where(fn (EloquentBuilder $q): EloquentBuilder => $q
                        ->where('subject_type', $owner->getMorphClass())->where('subject_id', $owner->getKey()))
                    ->orWhere(fn (EloquentBuilder $q): EloquentBuilder => $q
                        ->where('actor_type', $owner->getMorphClass())->where('actor_id', $owner->getKey()))
                    ->delete();
            });

            $this->log->record('billing.owner_erased', null, array_filter([
                'owner_type' => $owner->getMorphClass(),
                // Money the customer was still owed. Purging it silently would destroy a liability.
                'unspent_credit' => $credit,
            ]), AuditSource::System);

            return new ErasureReport($purged, $retained, $credit);
        });

        // Outside the transaction, and last: deleting the customer at the provider is irreversible and
        // cannot be rolled back with the local rows. It is a no-op unless the app asked for it.
        $this->customers->forget($owner);

        return $report;
    }

    /**
     * The credit the customer still had, per currency — a debt the package is about to forget.
     *
     * @return array<string, int>
     */
    private function outstandingCredit(Model $owner): array
    {
        $balances = CreditBalance::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('balance_minor', '!=', 0)
            ->get();

        $outstanding = [];

        foreach ($balances as $balance) {
            $outstanding[$balance->currency] = $balance->balance_minor;
        }

        return $outstanding;
    }

    private function owned(string $table, Model $owner): Builder
    {
        return DB::table($table)
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }
}
