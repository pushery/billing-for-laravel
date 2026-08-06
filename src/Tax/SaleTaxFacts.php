<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SupplyPlacement;

/**
 * Everything a document must freeze about how one sale was taxed.
 *
 * They travel together because they are one decision with several consequences. Split across separate
 * lookups they drift, and the drift has no symptom: a sale can carry a correct rate, be stated in a correct
 * country, and still be reported into a scheme it does not belong to.
 */
final readonly class SaleTaxFacts
{
    public function __construct(
        public Money $tax,
        public SupplyPlacement $placement,
        /** The country the document states the tax for, or null where the supply is taxed nowhere here. */
        public ?string $country,
        public int $rateBps,
        public TaxRateCategory $rateCategory,
        /**
         * Why no tax was charged, or null when tax was charged.
         *
         * Beside the amount rather than derived from it: a zero amount is the same value however it arose,
         * and an issuer that has to infer the reason from the number will infer the most common one.
         */
        public ?TaxExemptionReason $exemption = null,
        /**
         * When this supply became taxable and under which rule, or null where the sale has no period.
         *
         * Beside the tax rather than derived later: recomputing a tax point applies today's configuration
         * to a sale made under a different one, and the two answers are indistinguishable afterwards.
         */
        public ?TaxPointDecision $taxPoint = null,
    ) {}

    /**
     * The same decision with the tax restated, for a sale priced from its buyer-facing total.
     *
     * Only the amount moves; the placement, the country, the rate and the exemption are the regime's answers
     * and are not this method's to touch. It exists because a gross-priced sale settles its tax as the
     * REMAINDER of the total — the only split whose parts add back to what the buyer agreed to pay — while
     * everything else about how that sale is taxed was already decided the ordinary way.
     *
     * Deliberately narrow. A general `with()` would let a caller restate the country or the rate, and those
     * are exactly the fields whose whole value is that nobody downstream gets to revise them.
     */
    public function withTax(Money $tax): self
    {
        return new self(
            tax: $tax,
            placement: $this->placement,
            country: $this->country,
            rateBps: $this->rateBps,
            rateCategory: $this->rateCategory,
            exemption: $this->exemption,
            taxPoint: $this->taxPoint,
        );
    }

    /** Whether the sale carried no tax for a stated legal reason. */
    public function exempt(): bool
    {
        return $this->exemption instanceof TaxExemptionReason;
    }

    /** Whether the buyer accounts for the tax instead of the seller. */
    public function reverseCharge(): bool
    {
        return $this->placement->reverseCharge;
    }

    /** Whether this sale belongs in the cross-border consumer scheme's return. */
    public function reportableUnderOneStopShop(): bool
    {
        return $this->placement->reportableUnderOneStopShop;
    }

    /** Where the supply is taxed — at the buyer or at the seller. */
    public function placeRule(): PlaceOfSupplyRule
    {
        return $this->placement->rule;
    }
}
