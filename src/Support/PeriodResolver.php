<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\BillingPeriod;

/**
 * Which billing cycle a moment of usage belongs to.
 *
 * The cycle is the SUBSCRIPTION's, mirrored from the provider — an owner who renews on the 31st does
 * not have a calendar month, and bucketing their usage by one would bill part of it in the wrong cycle
 * (and, at a month boundary, into a cycle the provider has already invoiced). Only when there is no
 * subscription cycle to follow — no subscription at all, or a provider that has not told us one — does
 * it fall back to the calendar month, which is the honest answer for an owner who is not on a cycle. A moment
 * past the stored cycle of a subscription that still runs belongs to the cycle after it, which is not written
 * until that cycle's payment is collected.
 *
 * Everything is UTC. A local timestamp shifted by DST lands one hour either side of a boundary, which
 * is precisely enough to bill a customer's usage into the wrong month once a year.
 */
final readonly class PeriodResolver
{
    public function __construct(
        /** Read for the interval a cycle advances by; resolved from the container when nothing was handed in. */
        private ?Repository $config = null,
    ) {}

    public function forOwner(Model $owner, ?CarbonInterface $at = null): BillingPeriod
    {
        $moment = ($at ?? Carbon::now())->utc();

        $subscription = Subscription::model()::query()
            ->forOwner($owner)
            ->forMerchant(null)
            ->ofDefaultType()
            ->latest('id')
            ->first();

        $start = $subscription?->current_period_start;
        $end = $subscription?->current_period_end;

        if ($start instanceof CarbonInterface && $end instanceof CarbonInterface && $end > $start) {
            $period = new BillingPeriod($this->key($start), $start->utc(), $end->utc());

            // The stored cycle is the CURRENT one. Usage stamped outside it (a late flush of last
            // cycle's events, a back-dated correction) belongs to its own cycle, not to this one.
            if ($period->contains($moment)) {
                return $period;
            }

            // Past the stored cycle while the subscription still runs: the cycle has ended and the one after it is
            // not written yet. The local engine writes it once the closing payment is collected, which takes until
            // its next run, days for a direct debit and weeks in dunning; a provider's webhook takes minutes. Usage
            // in that gap belongs to the cycle that follows, so it is counted where that cycle will read it, stepped
            // forward by the interval the engine advances by.
            if ($moment >= $period->end && $this->stillRuns($subscription, $moment)) {
                return $this->following($period, $subscription, $moment);
            }
        }

        return $this->calendarMonth($moment);
    }

    /** Whether the subscription goes on into the cycle after the stored one: not ended, and not canceled before the moment. */
    private function stillRuns(Subscription $subscription, CarbonInterface $moment): bool
    {
        return $subscription->status !== SubscriptionState::Ended->value
            && ! $subscription->terminated()
            && ($subscription->ends_at === null || $subscription->ends_at->greaterThan($moment));
    }

    /** The cycle that holds the moment, stepped forward from the stored one the way the engine advances it. */
    private function following(BillingPeriod $period, Subscription $subscription, CarbonInterface $moment): BillingPeriod
    {
        $interval = new TierInterval($this->config ?? Container::getInstance()->make(Repository::class))->for($subscription->tier_key);

        while (! $period->contains($moment)) {
            $period = new BillingPeriod($this->key($period->end), $period->end, $interval->advance($period->end)->utc());
        }

        return $period;
    }

    /** The cycle key: the UTC date the cycle opened, which cannot collide with the next cycle's. */
    private function key(CarbonInterface $start): string
    {
        return $start->utc()->format('Y-m-d');
    }

    private function calendarMonth(CarbonInterface $moment): BillingPeriod
    {
        $start = $moment->copy()->startOfMonth();

        return new BillingPeriod(
            key: $start->format('Y-m'),
            start: $start,
            end: $start->copy()->addMonth(),
        );
    }
}
