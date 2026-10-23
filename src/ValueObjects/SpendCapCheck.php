<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * An owner's spend cap measured against a metered request: the cap, what the period's usage past the allowance costs
 * so far, and what the request would add.
 *
 * The spend is in the cap's currency. The cost is in the currency of the meter the request is on, and a cost in
 * another currency cannot be measured against the cap, so the cap admits such a request only while it costs nothing.
 */
final readonly class SpendCapCheck
{
    public function __construct(
        public Money $cap,
        public Money $spent,
        public Money $cost,
    ) {}

    /** What is left under the cap: zero once the spend has reached it. */
    public function remaining(): Money
    {
        return $this->spent->greaterThanOrEqual($this->cap) ? Money::zero($this->cap->currency) : $this->cap->minus($this->spent);
    }

    /** Whether the cap takes the request. A request that costs nothing always fits. */
    public function admits(): bool
    {
        if ($this->cost->isZero()) {
            return true;
        }

        return $this->cost->currency === $this->cap->currency && $this->cost->lessThanOrEqual($this->remaining());
    }
}
