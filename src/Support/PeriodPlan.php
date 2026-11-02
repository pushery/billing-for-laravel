<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * The plan a period of a subscription is billed at on a tier, where the package's own engine bills it.
 *
 * The tier's own plan, except while a consumer's year goes on month by month: the row then keeps `billing_interval` at
 * `month`, and each period is a month at the tier's monthly price, or at a twelfth of the price of its year, rounded
 * down, for a subscription sold before its terms named a monthly price or on a tier that names none.
 * {@see LocalBillingEngine} prices the periods by the same rule, so a proration valued here squares a month at the
 * price the month is billed at.
 */
final class PeriodPlan
{
    public static function of(PlanCatalog $plans, Subscription $subscription, string $tierKey): ?Plan
    {
        $own = $plans->planFor($tierKey);

        if ($subscription->billing_interval !== BillingInterval::Month || ! $own instanceof Plan || $own->interval !== BillingInterval::Year) {
            return $own;
        }

        $month = $subscription->monthly_terms_at === null ? null : $plans->planFor($tierKey, BillingInterval::Month);

        return $month ?? new Plan($tierKey, Money::of(intdiv($own->amount->minorUnits, 12), $own->amount->currency), BillingInterval::Month);
    }

    /**
     * The plan a change moves the subscription to, at the price its periods will be billed at: while a consumer's year
     * goes on month by month, the tier changed to goes on at its month as well.
     */
    public static function movedTo(PlanCatalog $plans, Subscription $subscription, Plan $plan): Plan
    {
        return $subscription->billing_interval === BillingInterval::Month ? (self::of($plans, $subscription, $plan->key) ?? $plan) : $plan;
    }

    /**
     * A price of one interval for the subscription's current period: the price itself where the period was cut to that
     * interval, and otherwise its rate for the period's time.
     *
     * A period is cut to the interval of the tier it opened on. Where a tier sold at another interval replaced that tier
     * inside the period, the price of its own interval would charge for time the period does not have: a year's price
     * for a month, or a month's price for a year. A period no interval reaches, a trial or one a pause extended, keeps
     * its price, and so does one only an interval reaches that no tier is sold at: a single day of a monthly catalog was
     * cut short, not cut to the interval of another tier.
     */
    public static function scaled(Money $price, BillingInterval $interval, Subscription $subscription): Money
    {
        $cut = $subscription->periodInterval();
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if (! $cut instanceof BillingInterval || $cut === $interval || ! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface || ! self::sold($cut)) {
            return $price;
        }

        $seconds = max(1, $interval->advance($start)->getTimestamp() - $start->getTimestamp());

        return Money::of((int) round($price->minorUnits * ($end->getTimestamp() - $start->getTimestamp()) / $seconds), $price->currency);
    }

    /** Whether a tier of the catalog is sold at this interval, its own or one of its further intervals. */
    private static function sold(BillingInterval $interval): bool
    {
        $container = Container::getInstance();
        $plans = $container->make(PlanCatalog::class);

        return array_any(array_keys($container->make(TierCatalog::class)->all()), static fn (string $key): bool => in_array($interval, $plans->intervalsFor($key), true));
    }
}
