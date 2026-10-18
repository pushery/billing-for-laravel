<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Proration for a subscription the package's own engine collects in advance: the rest of the paid period is squared
 * at once.
 *
 * The period was paid at the tier in force when it began. A swap takes effect immediately, so a dearer tier is
 * charged for the time left as an order of its own, and a cheaper one leaves the difference as credit that the next
 * payments spend first ({@see RestOfPeriod}). Each tier is valued over its own interval, so a monthly tier changed
 * for a yearly one costs a twelfth of the year for a month.
 *
 * {@see ArrearsProrationStrategy} is the answer for a subscription collected in arrears, where nothing has been paid
 * for the period in progress and the bill that closes it charges each tier for the days it held.
 */
final readonly class InAdvanceProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private RestOfPeriod $rest,
        private PlanCatalog $plans,
        private BillingEventLog $log,
    ) {}

    /**
     * What the swap costs now, or gives back as credit: positive for a dearer tier, negative for a cheaper one, zero
     * where the period was not paid. Null where the tier in force cannot be priced or the period is unknown.
     */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        $subscription = $this->subscriptionOf($billable);
        $current = $this->currentPlan($subscription);

        if (! $subscription instanceof Subscription || ! $current instanceof Plan) {
            return null;
        }

        return $this->rest->forTierChange($subscription, $current, $newPlan, Carbon::now());
    }

    /**
     * Square the rest of the period for the swap: charge the difference or credit it.
     *
     * Called before the tier moves, so the tier in force is the one being left.
     */
    public function applySwap(Model $billable, Plan $newPlan): void
    {
        $subscription = $this->subscriptionOf($billable);
        $current = $this->currentPlan($subscription);
        $now = Carbon::now();

        if (! $subscription instanceof Subscription || ! $current instanceof Plan) {
            return;
        }

        $difference = $this->rest->forTierChange($subscription, $current, $newPlan, $now);

        if (! $difference instanceof Money || $difference->isZero()) {
            return;
        }

        $this->rest->square($billable, $subscription, $difference, "{$newPlan->key} for the rest of the period", $now);

        // The order or the credit says what moved, never why. Without this, support looking at a charge in the
        // middle of a period has no way to tell it was a change of tier.
        $this->log->record('billing.proration_squared', $billable, [
            'from_tier' => $current->key,
            'to_tier' => $newPlan->key,
            'amount' => $difference->minorUnits,
            'currency' => $difference->currency,
        ], AuditSource::System);
    }

    /** The owner's default subscription at the platform, the one the account hub swaps. */
    private function subscriptionOf(Model $billable): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($billable)
            ->forMerchant(null)
            ->ofDefaultType()
            ->latest('id')
            ->first();
    }

    /** The plan of the tier the subscription is on. */
    private function currentPlan(?Subscription $subscription): ?Plan
    {
        $tierKey = $subscription?->tier_key;

        return $tierKey === null ? null : $this->plans->planFor($tierKey);
    }
}
