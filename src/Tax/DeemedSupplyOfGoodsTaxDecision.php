<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\DeemedSupplyOfGoodsRefused;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Exceptions\UnknownTaxCountry;
use Pushery\Billing\ValueObjects\DeemedSupplyOfGoodsTax;
use Pushery\Billing\ValueObjects\GoodsConsignment;
use Pushery\Billing\ValueObjects\Money;

/**
 * Where the platform's own supply of goods to the buyer is taxed, and at which rate, in a sale it is treated as
 * making itself.
 *
 * A platform that facilitates a sale of goods is in two cases treated as receiving the goods from the seller and
 * supplying them to the buyer (Art. 14a VAT Directive). The first supply is documented to the seller. This decides
 * the second, which carries the transport (Art. 36b) and the full price.
 *
 * ## The place
 *
 * - **Goods already in the Union, sold into another member state:** an intra-Community distance sale, taxed where
 *   the transport ends (Art. 33(a)).
 * - **Goods already in the Union, sold within the member state they are in:** a domestic supply there (Art. 32).
 *   That member state is the buyer's, so both cases are taxed at the destination.
 * - **An imported consignment of up to 150 euros:** a distance sale of imported goods declared under the import
 *   scheme, taxed where the transport ends (Art. 33(c)).
 *
 * One exception moves the place back: below the distance-sale threshold of Art. 59c, a distance sale of goods sent
 * from the member state the platform is established in is taxed there. That threshold is the platform's own, so
 * it is asked of the installation's {@see DistanceSaleThresholdMonitor}, which watches nothing unless the operator
 * withdrew the declaration that waives it.
 *
 * ## The rate
 *
 * The standard rate of that member state, unless the goods fall in a reduced band. The rate comes from the tables
 * the calculator prices from, in their order: the dated history at the tax point, then the configured matrix or the
 * tax profile's rates, then the shipped table. The shipped table carries standard rates only, so a reduced band in
 * a member state no configured table covers is refused rather than charged at the standard rate or at none.
 *
 * ## The moment
 *
 * The tax on both supplies arises when the payment is accepted (Art. 66a VAT Directive, Art. 41a of Implementing
 * Regulation (EU) No 282/2011), whenever the goods ship, so that moment is the tax point a dated rate is read at.
 *
 * ## Refusals
 *
 * A consignment that contradicts the paragraph the sale was classified under, goods distance sales do not cover, an
 * imported consignment above the threshold or of excise goods, a margin basis and a buyer outside the Union are all
 * refused. Each describes a supply that is not this one, so there is no tax of this supply to state for it.
 */
final readonly class DeemedSupplyOfGoodsTaxDecision
{
    public function __construct(
        /** The shipped standard rates, which answer for every member state no configured table covers. */
        private ShippedTaxRates $shipped,
        /** The configured matrix, or the active tax profile's own rates; null where neither exists. */
        private ?TaxRateMatrix $matrix = null,
        /**
         * The configured rate history as dated intervals, empty on an installation that keeps none.
         *
         * An empty table rather than null: it knows no member state, so the rate falls through to the matrix
         * exactly as a missing history did, and the container cannot hand over a null where it can build the
         * table.
         */
        private DatedTaxRateTable $history = new DatedTaxRateTable,
        /** The member state the platform is established in, as an ISO 3166 code, where the installation states one. */
        private ?string $establishedIn = null,
        /** The distance-sale threshold of the installation; null where nothing watches it. */
        private ?DistanceSaleThresholdMonitor $thresholds = null,
    ) {}

    /**
     * Decide the tax on the platform's supply of the goods to the buyer.
     *
     * @param  DeemedSupplyOfGoods  $supply  the paragraph the sale was classified under
     * @param  GoodsConsignment  $consignment  the consignment the classification was made from
     * @param  ?string  $goodsLocatedIn  for goods already in the Union, the member state they were in when the
     *                                   transport began; not read for an imported consignment
     * @param  ?string  $destinationCountry  the member state where the transport ends
     * @param  Money  $gross  what the buyer pays, tax included
     * @param  CarbonImmutable  $paymentAcceptedAt  when the payment was accepted
     * @param  TaxRateCategory  $category  the band the goods fall in, the standard one unless the goods are in a
     *                                     reduced band
     *
     * @throws DeemedSupplyOfGoodsRefused where the supply cannot be taxed as this one
     * @throws RegimeNotPermitted where the consignment was declared as taxed on the margin
     * @throws UnknownTaxCountry where no table carries a rate for the member state
     */
    public function decide(
        DeemedSupplyOfGoods $supply,
        GoodsConsignment $consignment,
        ?string $goodsLocatedIn,
        ?string $destinationCountry,
        Money $gross,
        CarbonImmutable $paymentAcceptedAt,
        TaxRateCategory $category = TaxRateCategory::Standard,
    ): DeemedSupplyOfGoodsTax {
        $this->assertTheConsignmentIsThisSupply($supply, $consignment);

        $destination = $this->memberState($destinationCountry)
            ?? throw DeemedSupplyOfGoodsRefused::destinationOutsideTheUnion($destinationCountry);

        $goodsIn = $supply === DeemedSupplyOfGoods::WithinTheUnion
            ? ($this->memberState($goodsLocatedIn) ?? throw DeemedSupplyOfGoodsRefused::goodsLocationUnknown($goodsLocatedIn))
            : null;

        $taxedIn = $destination;
        $rule = PlaceOfSupplyRule::Destination;

        if ($goodsIn !== null && $this->belowTheThreshold($goodsIn, $destination, $gross, $paymentAcceptedAt, $category)) {
            $taxedIn = $goodsIn;
            $rule = PlaceOfSupplyRule::Domestic;
        }

        $rateBps = $this->rateBps($taxedIn, $category, $paymentAcceptedAt);

        [$net, $tax] = $gross->baseFromMarkup($rateBps);

        return new DeemedSupplyOfGoodsTax(
            supply: $supply,
            taxedIn: $taxedIn,
            rule: $rule,
            rateCategory: $category,
            rateBps: $rateBps,
            gross: $gross,
            net: $net,
            tax: $tax,
            destinationCountry: $destination,
            goodsLocatedIn: $goodsIn,
            taxPoint: $paymentAcceptedAt,
        );
    }

    /**
     * Refuse a consignment that is not the supply the sale was classified as.
     *
     * The classifier asks the same questions before any money moves. They are asked again here because each answer
     * decides the place: an imported consignment is taxed under the import scheme and goods in the Union under the
     * Union's rules, goods installed where they are installed, and goods taxed on the margin carry no tax of their own.
     */
    private function assertTheConsignmentIsThisSupply(DeemedSupplyOfGoods $supply, GoodsConsignment $consignment): void
    {
        if ($consignment->imported !== ($supply === DeemedSupplyOfGoods::ImportedConsignment)) {
            throw DeemedSupplyOfGoodsRefused::consignmentContradictsTheSupply($supply);
        }

        if ($consignment->newMeansOfTransport || $consignment->installedOrAssembled) {
            throw DeemedSupplyOfGoodsRefused::outsideDistanceSales($consignment->newMeansOfTransport);
        }

        if ($consignment->imported && $consignment->subjectToExcise) {
            throw DeemedSupplyOfGoodsRefused::importedExciseGoods();
        }

        if ($consignment->imported && ! $consignment->importedWithinTheThreshold()) {
            throw DeemedSupplyOfGoodsRefused::importAboveTheThreshold($consignment->intrinsicValue->minorUnits ?? 0);
        }

        if (! new MarginSchemeAvailability()->permits($consignment->basis ?? TaxationBasis::Standard, SellerOfRecordPosture::PlatformDeemedSupplier, true)) {
            throw RegimeNotPermitted::deemedSupplyOfGoodsOnTheMargin($supply);
        }
    }

    /** The member state a code names, with a territory a member folds into itself resolved to it, or null. */
    private function memberState(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }

        return UnionMembership::isMember($country) ? UnionMembership::territoryOf($country) : null;
    }

    /**
     * Whether this distance sale is taxed at the platform, below the distance-sale threshold.
     *
     * Only goods sent from the member state the platform is established in can be: the threshold is a relief for a
     * supplier selling from home, and goods sent from another member state are taxed at the destination whatever the
     * platform sold. The sale counts towards the limit with its own net at the platform's rate, so the sale that
     * crosses the limit is taxed at the destination already.
     */
    private function belowTheThreshold(string $goodsIn, string $destination, Money $gross, CarbonImmutable $taxPoint, TaxRateCategory $category): bool
    {
        if ($goodsIn === $destination || $this->establishedIn === null || ! $this->thresholds instanceof DistanceSaleThresholdMonitor) {
            return false;
        }

        if (UnionMembership::territoryOf($this->establishedIn) !== $goodsIn || ! $this->thresholds->watched()) {
            return false;
        }

        [$net] = $gross->baseFromMarkup($this->rateBps($goodsIn, $category, $taxPoint));

        return $this->thresholds->ruleForSale($taxPoint->year, $gross->currency, $net->minorUnits) === PlaceOfSupplyRule::Domestic;
    }

    /**
     * The rate of a band in a member state, from the first table that covers the member state.
     *
     * The history and the matrix answer a band they do not carry with the standard rate, because a table that covers
     * a member state and carries no reduced band for it says that member state grants none. The shipped table says
     * nothing about reduced bands at all, so a reduced band it alone would answer is refused.
     */
    private function rateBps(string $country, TaxRateCategory $category, CarbonImmutable $taxPoint): int
    {
        if ($this->history->knowsCountry($country)) {
            return $this->history->rateAt($country, $category, $taxPoint);
        }

        if ($this->matrix instanceof TaxRateMatrix && $this->matrix->covers($country)) {
            return $this->matrix->rateFor($country, $category);
        }

        if ($category !== TaxRateCategory::Standard) {
            throw DeemedSupplyOfGoodsRefused::rateCategoryUnanswered($country, $category);
        }

        return $this->shipped->bps[$country] ?? throw UnknownTaxCountry::code($country);
    }
}
