<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\TaxRateCategory;

/**
 * The inputs a TaxCalculator needs to determine tax: who the customer is — the country, an optional VAT id,
 * whether they are a business (for EU reverse-charge / OSS handling) — and what was sold.
 *
 * The last two properties are APPENDED and optional, which is the whole design constraint. A rate depends on
 * both the destination and the kind of supply, but `TaxCalculator::calculate()` is a published contract with
 * implementations outside this package: changing its signature would be a fatal error in every one of them.
 * So the second dimension arrives inside the context that was already being passed, and every existing
 * construction keeps working and keeps meaning what it meant.
 */
final readonly class TaxContext
{
    /**
     * @param  TaxRateCategory  $rateCategory  which band of the destination's rates the supply falls in
     * @param  bool  $hasAudioVisualComponent  any audio or video part of it, however small — this closes the
     *                                         reduced band for the whole supply
     */
    public function __construct(
        public string $countryCode,
        public ?string $vatId = null,
        public bool $business = false,
        public bool $vatIdValid = false,
        public TaxRateCategory $rateCategory = TaxRateCategory::Standard,
        public bool $hasAudioVisualComponent = false,
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
