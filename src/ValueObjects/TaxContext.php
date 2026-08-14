<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\TaxRateCategory;

/**
 * The inputs a TaxCalculator needs to determine tax: who the customer is — the country, an optional VAT id,
 * whether they are a business (for EU reverse-charge / OSS handling) — and what was sold.
 *
 * The last three properties are APPENDED and optional, which is the whole design constraint. A rate depends
 * on the destination, the kind of supply AND the moment the supply was taxed, but `TaxCalculator::calculate()`
 * is a published contract with implementations outside this package: changing its signature would be a fatal
 * error in every one of them. So each further dimension arrives inside the context that was already being
 * passed, and every existing construction keeps working and keeps meaning what it meant.
 */
final readonly class TaxContext
{
    /**
     * @param  TaxRateCategory  $rateCategory  which band of the destination's rates the supply falls in
     * @param  bool  $hasAudioVisualComponent  any audio or video part of it, however small — this closes the
     *                                         reduced band for the whole supply
     * @param  ?CarbonImmutable  $taxPoint  WHEN the supply was taxed, where the caller knows it. The law binds
     *                                      the rate to the tax point rather than to the moment of lookup
     *                                      (Art. 93 VAT Directive), so a document written today for a supply
     *                                      made under a different rate must be able to say which moment it
     *                                      means. Null is not "today": it is "the caller does not know", and
     *                                      it takes the undated answer — the same one every call got before
     *                                      this property existed. A default of today would be the same trap
     *                                      with a better conscience, since the call site would look correct
     *                                      and the answer would still be pinned to when the code ran.
     */
    public function __construct(
        public string $countryCode,
        public ?string $vatId = null,
        public bool $business = false,
        public bool $vatIdValid = false,
        public TaxRateCategory $rateCategory = TaxRateCategory::Standard,
        public bool $hasAudioVisualComponent = false,
        public ?CarbonImmutable $taxPoint = null,
    ) {}

    /**
     * The same context, told when the supply was taxed.
     *
     * A wither rather than a seventh constructor argument at the one call site that knows the moment: the
     * context is assembled by the place-of-supply layer, which has no opinion about tax points, and the tax
     * point is decided by a layer that has no business rebuilding somebody else's context field by field.
     * Copying six properties by hand at that seam is how the two drift the next time one is appended.
     */
    public function at(?CarbonImmutable $taxPoint): self
    {
        return new self(
            countryCode: $this->countryCode,
            vatId: $this->vatId,
            business: $this->business,
            vatIdValid: $this->vatIdValid,
            rateCategory: $this->rateCategory,
            hasAudioVisualComponent: $this->hasAudioVisualComponent,
            taxPoint: $taxPoint,
        );
    }

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
