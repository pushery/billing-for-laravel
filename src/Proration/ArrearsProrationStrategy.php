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
use Pushery\Billing\Support\PeriodPlan;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Proration for the engine this package bills itself, which collects each period at its END.
 *
 * Nothing has been paid for the period in progress, so a swap has no unused paid time to give back. What a swap
 * changes is the bill that closes the period. That bill prices the period at the tier in force when it closes, and
 * the tier being left is owed its own price for the seat-days it held, so the difference is carried on the
 * subscription ({@see Subscription::accrueTierChange()}) and the cycle adds it. Each tier is billed for the days it
 * held, an upgrade and a downgrade alike, and no balance moves.
 *
 * {@see CreditBalanceProrationStrategy} gives back the unused remainder of a period that was paid in advance. This
 * engine has no such remainder, and crediting one would give away days nobody had paid for.
 */
final readonly class ArrearsProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private ProrationCalculator $calculator,
        private PlanCatalog $plans,
        private BillingEventLog $log,
    ) {}

    /**
     * What the swap adds to the bill that closes the period, or takes off it: the new price less the old one for
     * the part of the period still to come.
     *
     * Positive for an upgrade, negative for a downgrade, and zero once the period has run out. Null when the tier
     * in force cannot be priced, so the screen shows no estimate rather than a number that might be wrong.
     */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        $subscription = $this->subscriptionOf($billable);
        $current = $this->currentPlan($subscription);

        if (! $subscription instanceof Subscription || ! $current instanceof Plan) {
            return null;
        }

        [$remaining, $length] = $this->clock($subscription);

        [$from, $to] = $this->forThePeriod($subscription, $current, $newPlan);

        return $this->calculator->netForSwap($from, $to, $remaining, $length);
    }

    /**
     * Carry what the tier being left is owed for the days it held into the bill that closes the period.
     *
     * Called before the tier moves, so the tier in force is the one being left. A subscription that does not know
     * its period, or whose tier cannot be priced, has nothing to carry.
     */
    public function applySwap(Model $billable, Plan $newPlan): void
    {
        $subscription = $this->subscriptionOf($billable);
        $current = $this->currentPlan($subscription);

        if (! $subscription instanceof Subscription || ! $current instanceof Plan || $subscription->current_period_start === null) {
            return;
        }

        $before = $subscription->tier_adjustment_accrued;

        [$from, $to] = $this->forThePeriod($subscription, $current, $newPlan);
        $subscription->accrueTierChange($from, $to, Carbon::now());

        // The row says what the period's bill will carry, never why. Without this a support agent reading an
        // invoice whose plan line matches no list price has no way to see the change of tier behind it.
        $this->log->record('billing.tier_change_accrued', $billable, [
            'from_tier' => $current->key,
            'to_tier' => $newPlan->key,
            'accrued' => $subscription->tier_adjustment_accrued - $before,
            'currency' => $current->amount->currency,
        ], AuditSource::System);
    }

    /**
     * The tier being left and the one moved to, each at what it costs for the period in progress: the period is billed at
     * those prices, and a tier sold at another interval than the period's costs its rate for the period's time.
     *
     * @return array{Money, Money}
     */
    private function forThePeriod(Subscription $subscription, Plan $current, Plan $newPlan): array
    {
        $moved = PeriodPlan::movedTo($this->plans, $subscription, $newPlan);

        return [
            PeriodPlan::scaled($current->amount, $current->interval, $subscription),
            PeriodPlan::scaled($moved->amount, $moved->interval, $subscription),
        ];
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

    /**
     * The plan of the tier the subscription is on, at the price its periods are billed at: the price the cycle that
     * closes the period starts from.
     */
    private function currentPlan(?Subscription $subscription): ?Plan
    {
        $tierKey = $subscription?->tier_key;

        return ! $subscription instanceof Subscription || $tierKey === null ? null : PeriodPlan::of($this->plans, $subscription, $tierKey);
    }

    /**
     * Where the clock sits in the subscription's own period: seconds left, and how long the period is.
     *
     * Both from the stored period, never a calendar month: a subscription whose period has already run out has no
     * time left in it, and a month it was never billed for says nothing about it.
     *
     * @return array{int, int}
     */
    private function clock(Subscription $subscription): array
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return [0, 0];
        }

        $now = Carbon::now()->utc();

        return [
            (int) max(0, $now->diffInSeconds($end, false)),
            (int) max(0, $start->diffInSeconds($end, false)),
        ];
    }
}
