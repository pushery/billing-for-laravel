<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;

/**
 * The billing cycle usage is accounted into. It follows the SUBSCRIPTION's cycle, not the calendar:
 * an owner who renews on the 17th has a period running the 17th to the 17th, and bucketing their usage
 * by calendar month would bill part of it in the wrong cycle. The key is what the usage counter and the
 * outbox are grouped by, so it must be stable for a given cycle and never collide with the next one.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public string $key,
        public CarbonInterface $start,
        public CarbonInterface $end,
    ) {}

    public function contains(CarbonInterface $moment): bool
    {
        return $moment >= $this->start && $moment < $this->end;
    }
}
