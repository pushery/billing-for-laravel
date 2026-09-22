<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Exceptions\TaxStandingUnestablished;

/**
 * The two questions about the seller that a routed sale answers before the provider is reached.
 *
 * Whether anybody knows how the seller is taxed, and whether the posture can carry what they sell. Both are
 * asked on every lane that sells for a merchant: the direct payment and the three hosted ones. A hosted
 * session is a sale just as a direct charge is; the only difference is that the webhook writes its row
 * afterwards. So the conditions live here once, and each lane calls them instead of restating them.
 */
final readonly class SellerSaleGate
{
    public function __construct(private CreatorTaxStatusHold $taxStanding) {}

    /**
     * Refuse a sale for a merchant whose taxation nobody has established, once the hold is in force.
     *
     * A settlement document for such a sale would state a tax treatment nothing supports, and the two ways of
     * guessing fail in opposite directions, so holding is the answer. The hold carries its own start date and
     * holds nobody before it.
     */
    public function assertTaxStandingEstablished(Model $merchant): void
    {
        if ($this->taxStanding->blocksSales($merchant)) {
            throw TaxStandingUnestablished::forMerchant();
        }
    }

    /**
     * Refuse goods of a seller established outside the Union under the intermediary posture.
     *
     * Under intermediation the platform arranges somebody else's supply and its fee is its only turnover.
     * Goods sold by a seller established outside the Union to a consumer in it are not that: the platform
     * facilitating the sale is treated as having received and supplied them itself (Art. 14a(2) of the VAT
     * Directive, in Germany § 3 Abs. 3a UStG). This package has no document chain for that deemed supply, so
     * the sale is refused before any money moves instead of being recorded as the wrong transaction.
     *
     * Two limits, both in the safe direction. The buyer's status is not known here, so a sale to a business,
     * which the rule does not cover, is refused as well. And only goods are asked about: an electronic service
     * answers to its own deemed-supplier rule, which the posture resolver enforces. A sale whose archetype is
     * unknown is not treated as goods.
     */
    public function assertPostureCarriesTheSupply(Model $merchant, SellerOfRecordPosture $posture, ?TaxArchetype $archetype): void
    {
        if ($posture === SellerOfRecordPosture::PlatformIntermediary
            && $archetype === TaxArchetype::ConsumerGoods
            && $this->taxStanding->statusFor($merchant) === CreatorTaxStatus::NonUnionBusiness) {
            throw RegimeNotPermitted::intermediatedGoodsOfASellerOutsideTheUnion();
        }
    }
}
