<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The payment behind the period a subscriber is in, and the period it bought.
 *
 * The reference is whatever the driver's rails refund against. On Stripe that is the cycle's invoice, which the
 * rails resolve to the payment behind it.
 */
final readonly class SubscriptionPeriodPayment
{
    public function __construct(
        /** What the payment rails refund against. */
        public string $chargeReference,
        /** What was paid for the period, gross. */
        public Money $gross,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
    ) {}

    /**
     * The whole days the period runs.
     *
     * Rounded rather than truncated: a month-long period must not lose a day because its end was stored a second
     * early.
     */
    public function periodDays(): int
    {
        return max(0, (int) round($this->periodStart->diffInDays($this->periodEnd)));
    }

    /**
     * The whole days of the period already provided at a moment, from none up to the whole period.
     *
     * Truncated, and that is the buyer's side of the rounding: on the afternoon of the eighth day seven days were
     * provided, because the eighth was not provided in full.
     */
    public function elapsedDaysAt(CarbonInterface $moment): int
    {
        return max(0, min((int) floor($this->periodStart->diffInDays($moment)), $this->periodDays()));
    }
}
