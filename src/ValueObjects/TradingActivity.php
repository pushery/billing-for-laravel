<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * What a seller sold of goods in one window, and the sale that reached the activity threshold, if one did.
 *
 * Sales and proceeds are kept apart because the threshold asks about each on its own. The sale that reached
 * it is named rather than implied, so a changed standing can always point at the transaction that justified
 * the question.
 */
final readonly class TradingActivity
{
    public function __construct(
        public int $sales,
        public Money $proceeds,
        /** The charge reference of the sale that reached the threshold, or null where none did. */
        public ?string $reachedBy = null,
        public ?CarbonImmutable $reachedAt = null,
    ) {}

    public function reachedThreshold(): bool
    {
        return $this->reachedAt instanceof CarbonImmutable;
    }
}
