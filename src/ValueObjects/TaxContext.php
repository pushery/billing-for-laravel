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
        public bool $vatIdValid = false,
    ) {}

    /**
     * Whether this is a VALIDATED intra-EU business: flagged business, carrying a VAT id, AND that id proven
     * valid (via VIES). The reverse charge zero-rates the supply, so it must never rest on an id that was
     * merely present — a fake, or one that VIES could not confirm, would under-charge VAT.
     */
    public function isReverseChargeCandidate(): bool
    {
        return $this->business
            && $this->vatId !== null && trim($this->vatId) !== ''
            && $this->vatIdValid;
    }
}
