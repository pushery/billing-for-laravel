<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Pushery\Billing\ValueObjects\Money;

/**
 * The pure time-proration math the credit-balance drivers use to compute a mid-cycle
 * swap themselves, because those providers have no provider-side proration. It knows nothing about
 * subscriptions or the database: given an amount and where the clock sits in the period, it returns
 * the prorated portion; given two plan prices, it returns the net due now (charge positive, credit
 * negative). Currency safety is inherited from Money — mixing currencies throws.
 */
final readonly class ProrationCalculator
{
    /**
     * The portion of an amount that covers the unused remainder of the period. The remainder is
     * clamped into the period, so a stale or over-long clock can never bill more than the full amount
     * or less than zero; a non-positive period prorates to nothing.
     */
    public function proratedAmount(Money $amount, int $secondsRemaining, int $secondsInPeriod): Money
    {
        if ($secondsInPeriod <= 0) {
            return Money::zero($amount->currency);
        }

        $remaining = max(0, min($secondsRemaining, $secondsInPeriod));
        $prorated = (int) round($amount->minorUnits * $remaining / $secondsInPeriod);

        return Money::of($prorated, $amount->currency);
    }

    /**
     * The net amount due now to swap from the old price to the new price for the rest of the period:
     * the new plan's prorated charge less the old plan's unused credit. Positive means charge the
     * customer (an upgrade), negative means credit them (a downgrade), zero at a period edge.
     */
    public function netForSwap(Money $oldAmount, Money $newAmount, int $secondsRemaining, int $secondsInPeriod): Money
    {
        return $this->proratedAmount($newAmount, $secondsRemaining, $secondsInPeriod)
            ->minus($this->proratedAmount($oldAmount, $secondsRemaining, $secondsInPeriod));
    }
}
