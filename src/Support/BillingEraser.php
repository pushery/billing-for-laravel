<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * Everything else goes, and the stored webhook payloads are SCRUBBED rather than kept: they carry the
 * customer's email, name, billing address and card last four, and a right to erasure that cannot reach the
 * data is not a right to erasure.
 *
 * ## What this does NOT reach, and it used to claim it did
 *
 * There are no provider API keys here to delete. This package stores no secret of any kind — the merchant
 * row carries an account REFERENCE (`acct_…`), which is an identifier and goes with the row; a credential
 * would be a different thing and there is no column for one. `NoStoredCredentialsTest` holds that, so the
 * sentence cannot quietly stop being true.
 *
 * This paragraph used to say the owner's provider API keys go "FIRST and unconditionally", which described a
 * purge of something that does not exist. Read by an operator answering an erasure request, that is the
 * expensive direction to be wrong in: **if YOUR app stores a merchant's API keys, erasing them is your job.**
 * `BillableAccountDeleting` is the hook — dispatched first and outside the transaction, so a listener can
 * make a provider call — and it is where that deletion belongs.
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
        private SubjectScopedRecords $records = new SubjectScopedRecords,
    ) {}

    public function erase(Model $owner): ErasureReport
    {
        // Stop live billing FIRST, before anything is erased: an owner whose data is gone but whose
        // subscription keeps charging is a money leak AND a compliance breach (you cannot bill someone you
        // erased). Dispatched — not called directly — so the same listener serves an app's own delete flow,
        // and OUTSIDE the erase transaction below so the provider API call never runs inside the DB tx. The
        // listener degrades on a transient provider failure (logs + continues), so the erase is never orphaned.
        Event::dispatch(new BillableAccountDeleting($owner));

        $credit = $this->outstandingCredit($owner);

        $report = DB::transaction(function () use ($owner, $credit): ErasureReport {
            // The buyer axis. Every column name below arrives with it, so the same code erases along any
            // axis the package scopes records to — the merchant axis runs this exact path.
            $axis = OwnerScopedTables::ownerAxis();

            // Child tables go FIRST, while the parent rows still exist to join through. The delivery record
            // stays and only its payload goes: the row is what makes a failed effect replayable, and the
            // package's own account of what the provider sent.
            $purged = [
                ...$this->records->purgeCascaded($axis, $owner),
                ...$this->records->purge($axis, $owner),
                ...$this->records->scrub($axis, $owner),
            ];

            $retained = $this->records->unlink($axis, $owner, Carbon::now());

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
}
