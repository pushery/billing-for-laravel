<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\PeriodResolver;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Proration for a provider that has none: the unused remainder of the current plan becomes customer
 * credit, and the next order is offset against it.
 *
 * Stripe prorates on its own side, which is what DelegatedProrationStrategy defers to. Mollie and Adyen
 * have no such thing — a swap there is simply a new order at the new price, and without this the customer
 * pays twice for the same days. Bind this in place of the delegated strategy on those drivers.
 *
 * WHY THE CREDIT IS BOOKED AND THE CHARGE IS NOT. A swap has two halves: the unused old time (a credit,
 * which is money the customer is owed and which nothing else in the system will remember) and the new
 * plan's remaining time (a charge, which the next order raises anyway). Booking the credit here and
 * leaving the charge to the order is what keeps the two from being counted twice — this strategy never
 * takes money, it only ever records what is owed back.
 */
final readonly class CreditBalanceProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private ProrationCalculator $calculator,
        private CreditLedger $ledger,
        private PlanCatalog $plans,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private BillingEventLog $log,
    ) {}

    /**
     * The net amount due now for the swap: the new plan's prorated charge less the unused credit.
     *
     * Positive is an upgrade (the customer owes the difference), negative is a downgrade (they are owed
     * it). Null when the current plan cannot be priced — a preview that cannot be computed is shown as
     * unavailable rather than as a number that might be wrong, the same way the delegated strategy
     * refuses to fabricate one.
     */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        $current = $this->currentPlan($billable);

        if (! $current instanceof Plan) {
            return null;
        }

        [$remaining, $length] = $this->clock($billable);

        return $this->calculator->netForSwap($current->amount, $newPlan->amount, $remaining, $length);
    }

    public function applySwap(Model $billable, Plan $newPlan): void
    {
        $current = $this->currentPlan($billable);

        if (! $current instanceof Plan) {
            return;
        }

        [$remaining, $length] = $this->clock($billable);

        $unused = $this->calculator->proratedAmount($current->amount, $remaining, $length);

        // A swap at the very end of a period leaves nothing unused. Writing a zero movement would add a
        // ledger entry that says nothing happened, which is noise in the one place that has to stay
        // readable when a customer disputes their balance.
        if ($unused->isZero()) {
            return;
        }

        $this->ledger->credit($billable, $unused);

        // The balance alone says WHAT the customer has, never WHY. Without this line a support agent
        // looking at a credit has no way to tell a proration from a refund or a goodwill gesture.
        $this->log->record('billing.proration_credited', $billable, [
            'from_tier' => $current->key,
            'to_tier' => $newPlan->key,
            'amount' => $unused->minorUnits,
            'currency' => $unused->currency,
        ], AuditSource::System);
    }

    private function currentPlan(Model $billable): ?Plan
    {
        return $this->plans->planFor($this->tiers->resolve($billable)->key);
    }

    /**
     * Where the clock sits in the current period: seconds left, and how long the period is.
     *
     * Both are taken from the SAME period so they cannot disagree, and the remainder is measured from
     * now rather than stored, because a swap is priced at the moment it happens.
     *
     * @return array{int, int}
     */
    private function clock(Model $billable): array
    {
        $period = $this->periods->forOwner($billable);
        $now = Carbon::now()->utc();

        // Negative would mean the period already ended; the calculator clamps it, but returning a
        // negative here would let a caller reading this pair reach a different conclusion than the
        // calculator does about the same swap.
        $remaining = max(0, $now->diffInSeconds($period->end, false));
        $length = max(0, $period->start->diffInSeconds($period->end, false));

        return [(int) $remaining, (int) $length];
    }
}
