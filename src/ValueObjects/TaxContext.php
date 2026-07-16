<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The inputs a TaxCalculator needs to determine tax: the customer's country, an optional VAT id, and
 * whether they are a business (for EU reverse-charge / OSS handling).
 */
final readonly class TaxContext
{
    public function __construct(
        public string $countryCode,
        public ?string $vatId = null,
        public bool $business = false,
    ) {}

    /** Whether this looks like a validated intra-EU business (has a VAT id and is flagged business). */
    public function isReverseChargeCandidate(): bool
    {
        return $this->business && $this->vatId !== null && trim($this->vatId) !== '';
    }
}
