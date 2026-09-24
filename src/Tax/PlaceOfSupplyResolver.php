<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\RecipientTaxStatus;
use Pushery\Billing\Exceptions\PointOfSaleUnknown;
use Pushery\Billing\ValueObjects\SupplyPlacement;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * Decides where a supply is taxed, in two stages and in that order: the BUYER first, the product second.
 *
 * The order is the whole fix. A product's rule is written for consumers, and a validated business in
 * another country moves the place of supply whatever the product says. Reading the product first gives a
 * consumer answer for a business buyer: a real rate, charged to a real country, remitted to a tax authority
 * that was never owed it — and reported into a scheme that exists only for consumers, which makes the
 * return's population wrong rather than one of its numbers.
 *
 * Nothing about that document looks wrong, which is why this is a resolver with tests rather than a rule in
 * prose. The buyer cannot reclaim what was charged, the authority was not owed it, and the summary
 * declaration that should have existed does not.
 *
 * A sale made in person comes before both stages. Goods handed over at a point of sale are supplied there,
 * so neither the buyer nor the product's online rule is asked where it is taxed.
 *
 * It is jurisdiction-neutral: it knows "a validated business elsewhere shifts the place to them", never any
 * statute. Which countries form the union, and what the rates are, live in the rate table and the profile.
 */
final readonly class PlaceOfSupplyResolver
{
    /**
     * @param  ?string  $sellerCountry  the seller's own country, for the cross-border test. Unknown means
     *                                  cross-border cannot be PROVEN, and an unproven shift never happens.
     */
    /**
     * @param  ?string  $sellerCountry  the seller's own country, for the cross-border test. Unknown means
     *                                  cross-border cannot be PROVEN, and an unproven shift never happens.
     * @param  ?list<string>  $unionMembers  which countries share the seller's tax union. Null takes the
     *                                       shipped membership; a consumer elsewhere supplies their own.
     */
    public function __construct(
        private ?string $sellerCountry = null,
        private ?array $unionMembers = null,
    ) {}

    /**
     * Whether a country shares the seller's tax union.
     *
     * This is the question the consumer-scheme flag turns on, and it was the one nobody asked: "consumer,
     * taxed at destination, cross-border" is true of a sale to any country on earth. Without this test a
     * sale to a buyer outside the union lands in a return written for members only — which makes the
     * return's POPULATION wrong rather than one of its numbers, the same failure this class already refuses
     * to commit for a business buyer.
     */
    /** The country the seller is established in, where the installation states one. */
    public function sellerCountry(): ?string
    {
        return $this->sellerCountry !== null ? strtoupper($this->sellerCountry) : null;
    }

    /**
     * Whether a country is inside the union this profile defines.
     *
     * Public because the outbound exemption turns on exactly this question, and answering it a second time
     * at the caller — with a second country list — is how the two lists drift apart. The union is a fact the
     * jurisdiction profile supplies (`DefinesUnionMembership`), so it is asked here or it is guessed there.
     */
    public function insideUnion(string $country): bool
    {
        return $this->sharesUnion($country);
    }

    private function sharesUnion(string $country): bool
    {
        $members = $this->unionMembers ?? UnionMembership::members();

        return in_array(strtoupper($country), array_map(strtoupper(...), $members), true);
    }

    public function place(PlaceOfSupplyRule $productRule, TaxContext $context): SupplyPlacement
    {
        $recipient = $this->recipientStatus($context);

        // A sale made in person is placed where it is made, BEFORE the buyer stage and instead of it. A
        // business from another member state does not move goods handed over in a shop with its VAT id,
        // and a supply that is not a distance sale belongs in no consumer scheme.
        if ($productRule === PlaceOfSupplyRule::PointOfSale) {
            return new SupplyPlacement(
                rule: PlaceOfSupplyRule::PointOfSale,
                recipient: $recipient,
                reverseCharge: false,
                reportableUnderOneStopShop: false,
            );
        }

        $buyerCountry = strtoupper($context->countryCode);
        $seller = $this->sellerCountry !== null ? strtoupper($this->sellerCountry) : null;

        // A validated business in ANOTHER union country. The place moves to them and they account for the
        // tax; the consumer scheme never sees it. Cross-border must be provable — a domestic business
        // supply owes ordinary domestic tax, and zero-rating it would under-charge every home-country
        // business at once.
        if ($recipient === RecipientTaxStatus::UnionBusinessValidated && $seller !== null && $buyerCountry !== $seller) {
            return new SupplyPlacement(
                rule: PlaceOfSupplyRule::Destination,
                recipient: $recipient,
                reverseCharge: true,
                reportableUnderOneStopShop: false,
            );
        }

        // A business outside the union: no union tax arises, and no union scheme applies either.
        if ($recipient === RecipientTaxStatus::NonUnionBusiness) {
            return new SupplyPlacement(
                rule: $productRule,
                recipient: $recipient,
                reverseCharge: false,
                reportableUnderOneStopShop: false,
            );
        }

        // Everything else follows the product — including a domestic validated business, which is taxed
        // exactly like a domestic consumer and is equally outside the cross-border consumer scheme.
        $crossBorder = $seller !== null && $buyerCountry !== $seller;

        return new SupplyPlacement(
            rule: $productRule,
            recipient: $recipient,
            reverseCharge: false,
            reportableUnderOneStopShop: $recipient === RecipientTaxStatus::Consumer
                && $productRule === PlaceOfSupplyRule::Destination
                && $crossBorder
                // …and the buyer is actually IN the union. The scheme exists for supplies between members;
                // a sale to a third country belongs in no member's return at all.
                && $this->sharesUnion($buyerCountry),
        );
    }

    /**
     * The tax context a calculator should be handed for this sale.
     *
     * This is where the place rule actually bites, and it bites by choosing WHICH COUNTRY CODE goes in. A
     * calculator maps a country to a rate; it has no way to know that a particular product is taxed where
     * the seller is rather than where the buyer is. Deciding that here — rather than teaching the
     * calculator about products — keeps one rule in one place and leaves the rate table alone.
     */
    public function taxContextFor(PlaceOfSupplyRule $productRule, TaxContext $buyer, ?string $soldAt = null): TaxContext
    {
        // Taxed in the country of the point of sale, and with the buyer's registration left out on purpose: a
        // supply placed where it happens is taxed there whoever buys it, and a calculator handed a validated
        // id would zero a sale in a shop abroad as a cross-border reverse charge.
        if ($productRule === PlaceOfSupplyRule::PointOfSale) {
            if ($soldAt === null) {
                throw PointOfSaleUnknown::missing();
            }

            return new TaxContext(
                countryCode: strtoupper($soldAt),
                vatId: $buyer->vatId,
                business: $buyer->business,
                rateCategory: $buyer->rateCategory,
                hasAudioVisualComponent: $buyer->hasAudioVisualComponent,
                taxPoint: $buyer->taxPoint,
            );
        }

        $placement = $this->place($productRule, $buyer);

        if ($placement->rule === PlaceOfSupplyRule::Destination) {
            return $buyer;
        }

        // Taxed where the seller is: the seller's country decides the rate, whatever country the buyer is
        // in. With no seller country configured there is nothing to substitute, so the buyer's stands —
        // which charges tax rather than dropping it.
        return $this->sellerCountry === null
            ? $buyer
            : new TaxContext(
                countryCode: $this->sellerCountry,
                vatId: $buyer->vatId,
                business: $buyer->business,
                vatIdValid: $buyer->vatIdValid,
                // Everything else about the supply travels with it. Rebuilt without these, a reduced-band
                // product taxed at the seller fell back to the standard band.
                rateCategory: $buyer->rateCategory,
                hasAudioVisualComponent: $buyer->hasAudioVisualComponent,
                taxPoint: $buyer->taxPoint,
            );
    }

    /**
     * The country to record as "whose rate was applied", or null when the question does not arise.
     *
     * Null for a supply taxed where the seller is, and that is a correction rather than an omission. The
     * column means the CUSTOMER's country, and writing the seller's into it would hand every report that
     * reads it by its definition — a cross-border return, a country breakdown, an audit extract — the wrong
     * country for every such sale, without a single test going red. A supply taxed at the seller is simply
     * not one of those sales.
     */
    public function documentCountryFor(PlaceOfSupplyRule $productRule, TaxContext $buyer): ?string
    {
        return $this->place($productRule, $buyer)->reportableUnderOneStopShop ? strtoupper($buyer->countryCode) : null;
    }

    /**
     * What the buyer is, fail-closed.
     *
     * Everything unproven is a consumer: an id that is merely present, one a registry could not confirm, an
     * outage. Charging tax that was not owed can be corrected; not charging tax that was owed cannot.
     */
    public function recipientStatus(TaxContext $context): RecipientTaxStatus
    {
        if (! $context->business) {
            return RecipientTaxStatus::Consumer;
        }

        // A business that proved its registration. The existing candidate test already requires the id to
        // be present AND confirmed, so an unvalidated one never reaches here.
        //
        // A confirmed registration proves a business, not a union member. A provider asks registers beyond
        // the union's (Stripe confirms a `gb_vat` with the UK's), so the confirmation is followed by the
        // question the status is named after: is the buyer's country in the union? Outside it, the business
        // is outside the union's tax, and the reverse charge is the union's own mechanism.
        if ($context->isReverseChargeCandidate()) {
            return $this->sharesUnion($context->countryCode)
                ? RecipientTaxStatus::UnionBusinessValidated
                : RecipientTaxStatus::NonUnionBusiness;
        }

        // A business without a union registration is treated as outside it. A union business that failed to
        // prove itself is NOT this case — it falls back to consumer, which charges tax rather than dropping it.
        return $context->vatId === null || trim($context->vatId) === ''
            ? RecipientTaxStatus::NonUnionBusiness
            : RecipientTaxStatus::Consumer;
    }
}
