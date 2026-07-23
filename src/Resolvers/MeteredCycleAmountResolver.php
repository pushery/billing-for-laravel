<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\CycleAmountResolver;
use Pushery\Billing\Exceptions\CurrencyMismatch;
use Pushery\Billing\Exceptions\CycleAmountUnresolvable;
use Pushery\Billing\Models\SubscriptionItem;
use Pushery\Billing\Models\UsageCounter;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\Money;

/**
 * Rates a metered line from the package's own usage counters — what a local billing engine needs, since it
 * has no provider to rate the events for it.
 *
 * Name it on a line's `preprocessor`, or bind it as the CycleAmountResolver when every metered line is
 * priced this way.
 *
 * DO NOT BIND THIS ON A DRIVER THAT RATES USAGE REMOTELY. The tier's included allowance is netted here, and
 * the provider's own price nets it again — the customer gets the free units twice, and the invoice is short
 * by exactly one allowance with nothing to show for it. The MeteredComponent's own documentation says the
 * same thing from the other side: the allowance lives in the provider's price, and rating it in both places
 * is the mistake. This class is the local half of that split and belongs only where there is no other half.
 */
final readonly class MeteredCycleAmountResolver implements CycleAmountResolver
{
    public function __construct(private MeterCatalog $meters) {}

    public function resolve(SubscriptionItem $item, BillingPeriod $period): Money
    {
        $subscription = $item->subscription;
        $tierKey = $subscription?->tier_key;
        $component = $tierKey === null ? null : $this->meters->component($tierKey, $item->plan_key);

        if (! $component instanceof MeteredComponent) {
            throw CycleAmountUnresolvable::meterNotInCatalog($item->plan_key, $tierKey);
        }

        $unitPrice = $component->unitPrice;

        if (! $unitPrice instanceof Money) {
            throw CycleAmountUnresolvable::componentHasNoUnitPrice($item->plan_key);
        }

        if ($unitPrice->currency !== $item->currency) {
            // Billing a line in a currency other than its own is not something to paper over: it would put
            // a foreign-currency amount on the invoice and total it as if it were the same money.
            throw CurrencyMismatch::between($item->currency, $unitPrice->currency);
        }

        $packages = $this->billablePackages($item, $period, $component->included ?? 0, max(1, $component->packageSize));

        return $unitPrice->multipliedBy($packages);
    }

    /**
     * How many priced packages this line accrued in the cycle.
     *
     * The counter's `used` is everything that happened; `prepaid_used` is the part already paid for by
     * prepaid units, and the allowance is consumed before those. So what is left to bill is the usage
     * beyond both — subtracting only one of them charges for units the customer already bought.
     */
    private function billablePackages(SubscriptionItem $item, BillingPeriod $period, int $included, int $packageSize): int
    {
        $subscription = $item->subscription;

        $counter = UsageCounter::query()
            ->where('owner_type', $subscription?->owner_type)
            ->where('owner_id', $subscription?->owner_id)
            ->where('meter_key', $item->plan_key)
            ->where('period', $period->key)
            ->first();

        $billableUnits = max(0, ($counter->used ?? 0) - $included - ($counter->prepaid_used ?? 0));

        // A partial package is charged as a whole one, the way a priced package works everywhere else: a
        // package of 1 000 emails is the unit of sale, so 1 001 emails is two of them. Rounding down would
        // hand out the remainder of every package for free, on every cycle, to every customer.
        return intdiv($billableUnits + $packageSize - 1, $packageSize);
    }
}
