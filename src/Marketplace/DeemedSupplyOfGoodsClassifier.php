<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Tax\MarginSchemeAvailability;
use Pushery\Billing\ValueObjects\GoodsConsignment;

/**
 * Whether the platform is treated as the supplier of goods whose sale it facilitated, and under which paragraph.
 *
 * Pure: it reads the seller's standing, the consignment and whether the buyer is a business, and asks nothing
 * else. Whether an installation can carry the answer, with the numbers and the documents a deemed supply needs,
 * is the gate's question, asked after this one.
 *
 * ## The two paragraphs
 *
 * Goods imported in a consignment of an intrinsic value up to 150 euros make the platform their supplier whoever
 * sells them (Art. 14a(1) VAT Directive). Goods already in the Union do so only where the seller is established
 * outside it (Art. 14a(2)). Both need a buyer who is not a business and goods that distance sales cover.
 *
 * ## Who counts as what
 *
 * The platform may regard every seller as a business and every buyer as somebody who is not one, unless it knows
 * otherwise (Art. 5d of Implementing Regulation (EU) No 282/2011). For a seller, knowing otherwise is a recorded
 * standing as a private individual: a private sale is no supply anybody is taxed on, so nobody is deemed to make
 * it. For a buyer it is what the caller passes, a verified VAT number or the application's own word.
 *
 * ## When neither paragraph applies
 *
 * For a seller in the Union that is the ordinary case, and the sale stays theirs, arranged by the platform. For a
 * seller outside it, what remains is the seller's own supply in the Union, which would need their registration
 * there, so it is refused with the reason it is not a deemed supply. The reasons are tried in the order they rule
 * out the rule: a business buyer first, then goods distance sales never cover, then the limits on imports.
 */
final readonly class DeemedSupplyOfGoodsClassifier
{
    public function __construct(private MarginSchemeAvailability $margin = new MarginSchemeAvailability) {}

    /**
     * The paragraph that makes the platform the supplier of this sale, or null where neither does.
     *
     * @throws RegimeNotPermitted where the seller is established outside the Union and the sale is no deemed
     *                            supply, or where a deemed supply was declared as taxed on the margin
     */
    public function classify(CreatorTaxStatus $seller, GoodsConsignment $consignment, bool $buyerIsABusiness): ?DeemedSupplyOfGoods
    {
        if ($seller === CreatorTaxStatus::PrivateIndividual) {
            return null;
        }

        $outsideTheUnion = $seller === CreatorTaxStatus::NonUnionBusiness;
        $exclusion = $this->exclusion($consignment, $buyerIsABusiness);

        if ($exclusion instanceof RegimeNotPermitted) {
            if ($outsideTheUnion) {
                throw $exclusion;
            }

            return null;
        }

        $supply = match (true) {
            $consignment->imported => DeemedSupplyOfGoods::ImportedConsignment,
            $outsideTheUnion => DeemedSupplyOfGoods::WithinTheUnion,
            default => null,
        };

        // The platform is the supplier of a deemed supply, and the scheme is closed to it there. Asked of the
        // class that holds that line for every sale, rather than restated.
        if ($supply instanceof DeemedSupplyOfGoods
            && ! $this->margin->permits($consignment->basis ?? TaxationBasis::Standard, SellerOfRecordPosture::PlatformDeemedSupplier, true)) {
            throw RegimeNotPermitted::deemedSupplyOfGoodsOnTheMargin($supply);
        }

        return $supply;
    }

    /** Why neither paragraph reaches this sale, the first reason in the order above, or null where none does. */
    private function exclusion(GoodsConsignment $consignment, bool $buyerIsABusiness): ?RegimeNotPermitted
    {
        return match (true) {
            $buyerIsABusiness => RegimeNotPermitted::goodsOfASellerOutsideTheUnionSoldToABusiness(),
            $consignment->newMeansOfTransport,
            $consignment->installedOrAssembled => RegimeNotPermitted::goodsOfASellerOutsideTheUnionOutsideDistanceSales($consignment->newMeansOfTransport),
            $consignment->imported && $consignment->subjectToExcise => RegimeNotPermitted::importedExciseGoodsOfASellerOutsideTheUnion(),
            $consignment->imported && ! $consignment->importedWithinTheThreshold() => RegimeNotPermitted::importedConsignmentOfASellerOutsideTheUnionAboveTheThreshold(
                $consignment->intrinsicValue->minorUnits ?? 0,
            ),
            default => null,
        };
    }
}
