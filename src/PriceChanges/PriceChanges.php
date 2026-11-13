<?php

declare(strict_types=1);

namespace Pushery\Billing\PriceChanges;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Pushery\Billing\Models\PriceChangeAnnouncement;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * The price a cycle of a subscription is billed at, once declared price changes are taken into account.
 *
 * A change holds for a subscription from the day its announcement named, and a cycle that begins on or after that day is
 * billed at the new price. A subscription that was never told keeps the old price, so nobody pays a price they were not
 * given notice of. One that began on or after the day of a change ordered at the new price, and pays it without being
 * told. Where two changes have come for the same tier, the one with the later day holds.
 */
final readonly class PriceChanges
{
    public function __construct(private DeclaredPriceChanges $declared) {}

    /** The tier's base price for the cycle the subscription is in: the newest change that holds for it by then, or `$own`. */
    public function priceFor(Subscription $subscription, string $tier, Money $own): Money
    {
        $cycleStart = $subscription->current_period_start;

        return $cycleStart instanceof DateTimeInterface
            ? $this->newest($subscription, CarbonImmutable::instance($cycleStart), $own, static fn (PriceChange $change): ?Money => $change->priceOf($tier))
            : $own;
    }

    /**
     * The price per package of the tier's meter for the cycle that begins at `$cycleStart`: the newest change that holds
     * for the subscription by then, or `$own`.
     */
    public function meterPriceFor(Subscription $subscription, string $tier, string $meter, DateTimeInterface $cycleStart, Money $own): Money
    {
        return $this->newest(
            $subscription,
            CarbonImmutable::instance($cycleStart),
            $own,
            static fn (PriceChange $change): ?Money => $change->meterPriceOf($tier, $meter),
        );
    }

    /**
     * The price the newest change that holds for the subscription by the start of the cycle gives, or `$own` where none
     * does.
     *
     * @param  Closure(PriceChange): ?Money  $priceOf  the new price a change gives, or null where it leaves this price alone
     */
    private function newest(Subscription $subscription, CarbonImmutable $start, Money $own, Closure $priceOf): Money
    {
        $price = $own;
        $latest = null;

        foreach ($this->declared->all() as $change) {
            $new = $priceOf($change);
            $day = $new instanceof Money ? $this->dayFor($change, $subscription) : null;

            if (! $new instanceof Money || ! $day instanceof CarbonImmutable || $day->greaterThan($start) || ($latest instanceof CarbonImmutable && $day->lessThan($latest))) {
                continue;
            }

            $latest = $day;
            $price = $new;
        }

        return $price;
    }

    /**
     * The day the change holds for the subscription from, or null while it holds for it on no day yet.
     *
     * The change's own day for a subscription that began on or after it, the day its announcement named for one that
     * began before it, and none for one that was never told.
     *
     * The stored day is read as the calendar day it names, at midnight UTC like every day of a change. The date cast
     * reads it at midnight in the application's zone, which east of UTC is the evening before in UTC.
     */
    private function dayFor(PriceChange $change, Subscription $subscription): ?CarbonImmutable
    {
        $began = $subscription->started_at;

        if ($began instanceof DateTimeInterface && CarbonImmutable::instance($began)->utc()->startOfDay()->greaterThanOrEqualTo($change->effectiveOn)) {
            return $change->effectiveOn;
        }

        $announcement = PriceChangeAnnouncement::model()::query()
            ->where('billing_subscription_id', $subscription->getKey())
            ->where('price_change', $change->key)
            ->first();

        return $announcement instanceof PriceChangeAnnouncement ? CarbonImmutable::parse($announcement->effective_on->toDateString(), 'UTC') : null;
    }
}
