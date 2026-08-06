<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * Where a fee is taxed, and which return it belongs in.
 *
 * The scheme travels with the country because they are one decision with two consequences. A fee charged at
 * the right rate to the right country and reported into the wrong scheme has no visible symptom, and it
 * corrupts the population of two returns rather than the amount of one line.
 */
final readonly class FeePlacement
{
    public function __construct(
        /** The country whose tax applies, or null where it could not be established. */
        public ?string $country,
        /** Whether it belongs in the cross-border consumer scheme. */
        public bool $reportableUnderOneStopShop,
        /** Whether the fee is taxable at all. False only where the country could not be established. */
        public bool $taxable,
    ) {}

    /** Whether this placement is usable — a caller must not issue a document on an unresolved one. */
    public function resolved(): bool
    {
        return $this->country !== null;
    }
}
