<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\BillingPeriod;

/**
 * Which billing cycle a moment of usage belongs to.
 *
 * The cycle is the SUBSCRIPTION's, mirrored from the provider — an owner who renews on the 31st does
 * not have a calendar month, and bucketing their usage by one would bill part of it in the wrong cycle
 * (and, at a month boundary, into a cycle the provider has already invoiced). Only when there is no
 * subscription cycle to follow — no subscription at all, or a provider that has not told us one — does
 * it fall back to the calendar month, which is the honest answer for an owner who is not on a cycle.
 *
 * Everything is UTC. A local timestamp shifted by DST lands one hour either side of a boundary, which
 * is precisely enough to bill a customer's usage into the wrong month once a year.
 */
final readonly class PeriodResolver
{
    public function forOwner(Model $owner, ?CarbonInterface $at = null): BillingPeriod
    {
        $moment = ($at ?? Carbon::now())->utc();

        $subscription = Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
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
        }

        return $this->calendarMonth($moment);
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
