<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CustomerRegistry;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillableAccountDeleting;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\CreditBalance;
use Pushery\Billing\ValueObjects\ErasureReport;
use Pushery\Billing\ValueObjects\MerchantScope;

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
 * ## Both sides of a sale
 *
 * The person is erased on every axis the package scopes records to: as the buyer who holds subscriptions and
 * invoices, and as the merchant named on routed charges, payout records and tax standings. The merchant's
 * operational rows go, its financial records are unlinked and stamped with `merchant_erased_at`, exactly as the
 * buyer's are. The subscriptions the merchant's fans hold with it are among the rows that go, so they are ended
 * at the provider first, in the same listener that ends the person's own: a subscription left running there
 * would renew for a merchant who no longer exists, with no local row left to show it.
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
        $this->assertSaved($owner);

        // Stop live billing FIRST, before anything is erased: an owner whose data is gone but whose
        // subscription keeps charging is a money leak AND a compliance breach (you cannot bill someone you
        // erased). Dispatched — not called directly — so the same listener serves an app's own delete flow,
        // and OUTSIDE the erase transaction below so the provider API call never runs inside the DB tx. The
        // listener degrades on a transient provider failure (logs + continues), so the erase is never orphaned.
        // It also ends what others hold with the person as a merchant, before the merchant axis purges it.
        Event::dispatch(new BillableAccountDeleting($owner));

        $report = $this->eraseRecords($owner);

        // Outside the transaction, and last: deleting the customer at the provider is irreversible and
        // cannot be rolled back with the local rows. It is a no-op unless the app asked for it.
        $this->customers->forget($owner);

        return $report;
    }

    /**
     * Erase what this package holds about the person, and reach nothing at a provider.
     *
     * `erase()` is three steps: it ends the person's billing at the provider through `BillableAccountDeleting`,
     * erases the records here, and has the provider forget the customer where the configuration asks for it.
     * This is the middle step alone, and the one `erase()` itself runs, so there is one way through the tables.
     *
     * A host that restores a backup and then replays the erasures made since needs exactly this. The provider
     * did its part when the person was first erased, and the restored rows show subscriptions as running that
     * the provider ended long ago: ending them again would act on a state the restore has moved.
     */
    public function eraseRecords(Model $owner): ErasureReport
    {
        $this->assertSaved($owner);

        $credit = $this->outstandingCredit($owner);

        return DB::transaction(function () use ($owner, $credit): ErasureReport {
            $now = Carbon::now();
            $purged = [];
            $retained = [];
            $axes = [];

            // Every axis the package scopes records to: the buyer who holds a subscription, and the merchant on
            // the other side of a routed sale. A person can be both, and a merchant's rows carry no owner
            // columns at all, so an erasure that walked the buyer axis alone stepped past everything the
            // package holds about a merchant and reported success. Every column name below arrives with the
            // axis, so both run the same code.
            foreach (OwnerScopedTables::axes() as $axis) {
                // Child tables go FIRST, while the parent rows still exist to join through. The delivery
                // record stays and only its payload goes: the row is what makes a failed effect replayable, and
                // the package's own account of what the provider sent.
                $axisPurged = [
                    ...$this->records->purgeCascaded($axis, $owner),
                    ...$this->records->purge($axis, $owner),
                    ...$this->records->scrub($axis, $owner),
                ];

                $axisRetained = $this->records->unlink($axis, $owner, $now);

                $axes[$axis->name] = ['purged' => $axisPurged, 'retained' => $axisRetained];
                $purged = $this->sum($purged, $axisPurged);
                $retained = $this->sum($retained, $axisRetained);
            }

            // The coupons the person issued as a merchant. They name the merchant by the sentinel string rather
            // than by morph columns, so no axis reaches them, and they go rather than come loose: a coupon whose
            // merchant is gone is an offer nobody makes any more, and loosened it would read as the platform's.
            $merchant = OwnerScopedTables::merchantAxis()->name;
            $coupons = $this->purgeCouponsIssuedBy($owner);
            $axes[$merchant]['purged'] = $this->sum($axes[$merchant]['purged'] ?? [], $coupons);
            $axes[$merchant]['retained'] ??= [];
            $purged = $this->sum($purged, $coupons);

            // The owner's own audit rows go with them. Then ONE row records that the erasure happened:
            // accountability (Art. 5(2)) means being able to show it was done — and that record must not
            // itself become a fresh copy of the personal data, so it carries the morph class and nothing
            // that could identify the person.
            // Audit rows are append-only for a caller that holds one. An erasure is one of the two things
            // that remove them, and it deletes by query, which the model's guard does not see.
            BillingEvent::model()::query()
                ->where('subject_type', $owner->getMorphClass())
                ->where('subject_id', $owner->getKey())
                ->delete();

            // A row in which the person only acted is the record of what happened to someone else: a refund a
            // support agent made to another customer, a change a member made to their team's plan. It stays, so
            // that history and the account of it outlive the agent's erasure, and it loses the person: the actor
            // columns are cleared, as the model's guard does not see a query.
            BillingEvent::model()::query()
                ->where('actor_type', $owner->getMorphClass())
                ->where('actor_id', $owner->getKey())
                ->toBase()
                ->update(['actor_type' => null, 'actor_id' => null]);

            $this->log->record('billing.owner_erased', null, array_filter([
                'owner_type' => $owner->getMorphClass(),
                // Money the customer was still owed. Purging it silently would destroy a liability.
                'unspent_credit' => $credit,
            ]), AuditSource::System);

            return new ErasureReport($purged, $retained, $credit, $axes);
        });
    }

    /**
     * Delete the coupons a person issued as a merchant, and the redemptions of them, and count both.
     *
     * The redemptions go first and on their own, rather than through the cascade on their key: the count is part
     * of the report, and a row removed by a cascade is a row nobody counted.
     *
     * @return array<string, int>
     */
    private function purgeCouponsIssuedBy(Model $owner): array
    {
        $coupons = DB::table('billing_coupons')->where('merchant_uid', MerchantScope::forMerchant($owner)->uid());

        $redemptions = DB::table('billing_coupon_redemptions')->whereIn('coupon_id', (clone $coupons)->select('id'))->delete();

        return array_filter(['billing_coupon_redemptions' => $redemptions, 'billing_coupons' => $coupons->delete()]);
    }

    /**
     * Refuse an owner that was never saved.
     *
     * Every table is filtered by the owner's key, and a filter on no key matches the rows that belong to nobody,
     * which are the rows an erasure must leave alone. An owner without a key holds nothing to erase.
     */
    private function assertSaved(Model $owner): void
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Only a saved owner can be erased: an owner with no key holds no records, and a filter on no key would match the rows that belong to nobody.');
        }
    }

    /**
     * Add one axis's counts to the total, per table. A table on two axes, such as the subscriptions a person
     * holds and the ones held with them as a merchant, counts the rows of both.
     *
     * @param  array<string, int>  $total
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function sum(array $total, array $counts): array
    {
        foreach ($counts as $table => $rows) {
            $total[$table] = ($total[$table] ?? 0) + $rows;
        }

        return $total;
    }

    /**
     * The credit the customer still had, per currency — a debt the package is about to forget.
     *
     * @return array<string, int>
     */
    private function outstandingCredit(Model $owner): array
    {
        $balances = CreditBalance::model()::query()
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
