<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\RecordsPrivateGoodsSellers;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Contracts\SuppliesSellerRecords;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Exceptions\SellerRecordIncomplete;
use Pushery\Billing\Exceptions\TaxStandingUnestablished;
use Pushery\Billing\Exceptions\TradingStandingUndeclared;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

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
    public function __construct(
        private CreatorTaxStatusHold $taxStanding,
        /** Null where the gate was built by hand; the container's counter answers then. */
        private ?SellerActivityCounter $activity = null,
    ) {}

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

    /**
     * Refuse a further sale of goods by a seller who reached the activity threshold and has not declared since.
     *
     * Only goods sold under intermediation, because that is where the question is whether a private seller
     * has become a business. A business standing answers it whenever it was declared. A private one, or none,
     * answers it only if it was declared after the threshold was reached: a statement made before the seller
     * sold this much was not a statement about a seller who had.
     *
     * The sale that reached the threshold went through; the one after it waits.
     */
    public function assertTradingStandingDeclared(Model $merchant, SellerOfRecordPosture $posture, ?TaxArchetype $archetype, ?CarbonImmutable $now = null): void
    {
        if ($posture !== SellerOfRecordPosture::PlatformIntermediary || $archetype !== TaxArchetype::ConsumerGoods) {
            return;
        }

        $now ??= CarbonImmutable::now();

        if ($this->taxStanding->statusFor($merchant, $now)->isBusiness()) {
            return;
        }

        $activity = ($this->activity ?? Container::getInstance()->make(SellerActivityCounter::class))
            ->activityAround($merchant, $now);

        if (! $activity->reachedAt instanceof CarbonImmutable) {
            return;
        }

        $declaredSince = CreatorTaxStatusRecord::model()::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->where('source', CreatorTaxStatusSource::SelfDeclaration->value)
            ->where('created_at', '>=', $activity->reachedAt)
            ->exists();

        if (! $declaredSince) {
            throw TradingStandingUndeclared::forMerchant($activity->reachedAt);
        }
    }

    /**
     * Refuse a sale of goods by a private individual whose record lacks what the regime requires of them.
     *
     * Only where the active profile keeps such a record and a record source is bound: without a source there
     * is nothing to read, which `billing:doctor` and the reporting check already report. A seller who sells as
     * a business, or a company, is not a private individual and is asked for a different record by the
     * reporting duty.
     */
    public function assertGoodsSellerRecordComplete(Model $merchant, SellerOfRecordPosture $posture, ?TaxArchetype $archetype, ?CarbonImmutable $now = null): void
    {
        if ($posture !== SellerOfRecordPosture::PlatformIntermediary || $archetype !== TaxArchetype::ConsumerGoods) {
            return;
        }

        $container = Container::getInstance();
        $profile = $this->reportingProfile();
        $records = $container->bound(SuppliesSellerRecords::class) ? $container->make(SuppliesSellerRecords::class) : null;

        if (! $profile instanceof RecordsPrivateGoodsSellers || ! $records instanceof SuppliesSellerRecords) {
            return;
        }

        if ($records->isLegalEntity($merchant) || $this->taxStanding->statusFor($merchant, $now ?? CarbonImmutable::now())->isBusiness()) {
            return;
        }

        $missing = $container->make(SellerRecordCompleteness::class)
            ->unsatisfied($profile->fieldsForPrivateGoodsSeller(), $records->valuesFor($merchant));

        if ($missing !== []) {
            throw SellerRecordIncomplete::forPrivateGoodsSeller($missing);
        }
    }

    /** The bound reporting profile, typed by its contract: a host may bind one that keeps no such record. */
    private function reportingProfile(): ReportingProfile
    {
        return Container::getInstance()->make(ReportingProfile::class);
    }
}
