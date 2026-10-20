<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\DocumentsDeemedSupplyOfGoods;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Contracts\RecordsPrivateGoodsSellers;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Contracts\SuppliesArchetypeRegimes;
use Pushery\Billing\Contracts\SuppliesSellerRecords;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Exceptions\SellerDeclarationMissing;
use Pushery\Billing\Exceptions\SellerRecordIncomplete;
use Pushery\Billing\Exceptions\TaxStandingUnestablished;
use Pushery\Billing\Exceptions\TradingStandingUndeclared;
use Pushery\Billing\Invoicing\Guards\RegimePostureGuard;
use Pushery\Billing\Models\CreatorTaxStatusRecord;
use Pushery\Billing\Models\TaxIdVerification;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Pushery\Billing\Tax\StandingTaxIdVerification;
use Pushery\Billing\ValueObjects\GoodsConsignment;

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
        /** Null where the gate was built by hand; the container's declarations answer then. */
        private ?SellerDeclarations $declarations = null,
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
     * Refuse a sale of goods the posture cannot carry, and name the paragraph of one the platform supplies itself.
     *
     * Under intermediation the platform arranges somebody else's supply and its fee is its only turnover. Two
     * kinds of sale of goods are not that: an imported consignment worth up to 150 euros, whoever sells it, and
     * goods already in the Union, sold by a seller established outside it to somebody who is not a business.
     * There the platform is treated as having received and supplied the goods itself (Art. 14a VAT Directive, in
     * Germany § 3 Abs. 3a UStG). {@see DeemedSupplyOfGoodsClassifier} decides which, from the consignment the lane
     * passes and from whether the buyer is a business, and refuses the goods of a seller outside the Union that
     * are no deemed supply either, because what remains is that seller's own supply in the Union.
     *
     * ## Where the profile documents such a supply
     *
     * A jurisdiction profile that implements {@see DocumentsDeemedSupplyOfGoods} has the documents, returns and
     * records of both legs, so the sale goes ahead as the platform's own supply and this returns its paragraph. The
     * lane then books it under `PlatformDeemedSupplier` and the commission chain, whatever posture the installation
     * configured, and the row and the documents keep the paragraph from then on. It is asked under both postures that
     * name a regime: a regime the profile compels for goods sold between private people does not reach a supply the
     * law makes the platform's. What the operator has to have done first is asked here too: an imported consignment
     * needs the import scheme number, and goods already in the Union the statement that the platform declares their
     * supply in the Union scheme.
     *
     * Under any other profile a classified deemed supply is refused before any money moves, an imported consignment
     * first where no import scheme number is configured, since that is the part an operator can put right.
     *
     * ## Without a consignment
     *
     * Nothing can then tell a deemed supply from the seller's own. The goods of a seller outside the Union are
     * refused as they always were, and the goods of a seller in the Union pass as they always did: theirs is a
     * deemed supply only where they are imported, which nothing here knows then.
     *
     * Only goods are asked about: an electronic service answers to its own deemed-supplier rule, which the posture
     * resolver enforces. A sale whose archetype is unknown is not treated as goods.
     *
     * Every sale that is no documented deemed supply is held against the regime the jurisdiction profile compels for
     * what is sold, {@see self::assertPostureCarriesTheCompelledRegime()}.
     *
     * @return ?DeemedSupplyOfGoods the paragraph of a deemed supply the profile documents, null for every other sale
     */
    public function assertPostureCarriesTheSupply(
        Model $merchant,
        SellerOfRecordPosture $posture,
        ?TaxArchetype $archetype,
        ?Model $buyer = null,
        ?GoodsConsignment $consignment = null,
    ): ?DeemedSupplyOfGoods {
        $documented = $this->documentedDeemedSupply($merchant, $posture, $archetype, $buyer, $consignment);

        if ($documented instanceof DeemedSupplyOfGoods) {
            return $documented;
        }

        $this->assertPostureCarriesTheCompelledRegime($posture, $archetype);

        if ($posture !== SellerOfRecordPosture::PlatformIntermediary || $archetype !== TaxArchetype::ConsumerGoods) {
            return null;
        }

        $seller = $this->taxStanding->statusFor($merchant);

        if (! $consignment instanceof GoodsConsignment) {
            if ($seller === CreatorTaxStatus::NonUnionBusiness) {
                throw RegimeNotPermitted::intermediatedGoodsOfASellerOutsideTheUnion();
            }

            return null;
        }

        $supply = new DeemedSupplyOfGoodsClassifier()->classify($seller, $consignment, $this->buysAsABusiness($buyer));

        if (! $supply instanceof DeemedSupplyOfGoods) {
            return null;
        }

        if ($supply === DeemedSupplyOfGoods::ImportedConsignment) {
            $this->assertImportSchemeNumberConfigured();
        }

        throw RegimeNotPermitted::deemedSupplyOfGoodsNotDocumented($supply);
    }

    /**
     * The paragraph of a deemed supply of goods the profile documents, or null where this sale is no such supply.
     *
     * Null as well where the question does not arise: a sale that is no goods, one that names no consignment, a
     * posture without a regime, and a profile that documents no deemed supply. Each of those is answered by the rest
     * of the gate exactly as before.
     *
     * @throws RegimeNotPermitted where the goods of a seller outside the Union are no deemed supply, or where the
     *                            supply is one and the operator has not done what it needs
     * @throws SellerDeclarationMissing where the seller has declared nothing the platform may rely on, or a later
     *                                  signal contradicts what it declared
     */
    private function documentedDeemedSupply(Model $merchant, SellerOfRecordPosture $posture, ?TaxArchetype $archetype, ?Model $buyer, ?GoodsConsignment $consignment): ?DeemedSupplyOfGoods
    {
        if ($archetype !== TaxArchetype::ConsumerGoods
            || ! $consignment instanceof GoodsConsignment
            || ! new RegimePostureGuard()->hasAnyRegime($posture)
            || ! $this->profile() instanceof DocumentsDeemedSupplyOfGoods) {
            return null;
        }

        $supply = new DeemedSupplyOfGoodsClassifier()->classify($this->taxStanding->statusFor($merchant), $consignment, $this->buysAsABusiness($buyer));

        if ($supply === DeemedSupplyOfGoods::ImportedConsignment) {
            $this->assertImportSchemeNumberConfigured();
        }

        if ($supply === DeemedSupplyOfGoods::WithinTheUnion
            && Container::getInstance()->make(Repository::class)->get('billing.marketplace.deemed_supply_of_goods.union_scheme') !== true) {
            throw RegimeNotPermitted::deemedSupplyOfGoodsWithoutTheUnionScheme();
        }

        // The platform's records of its own supply describe the goods, and its document to the buyer names them.
        if ($supply instanceof DeemedSupplyOfGoods && $consignment->description === null) {
            throw RegimeNotPermitted::deemedSupplyOfGoodsUndescribed($supply);
        }

        // The platform is protected for a supply it is treated as making only where it relied on what the seller
        // declared and had no reason to doubt it (Art. 5c Implementing Regulation 282/2011). So the sale waits for a
        // declaration, and for a new one after a signal that contradicts the last, before any money moves.
        if ($supply instanceof DeemedSupplyOfGoods) {
            ($this->declarations ?? Container::getInstance()->make(SellerDeclarations::class))->assertDeclared($merchant);
        }

        return $supply;
    }

    /** Refuse an imported consignment where no import scheme number, IM followed by ten digits, is configured. */
    private function assertImportSchemeNumberConfigured(): void
    {
        $configured = $this->configuredImportSchemeNumber();

        if ($configured === null || preg_match('/^IM\d{10}$/', $configured) !== 1) {
            throw RegimeNotPermitted::importedConsignmentWithoutAnImportSchemeNumber($configured);
        }
    }

    /**
     * Refuse a sale whose archetype the jurisdiction profile holds to a regime this posture cannot carry.
     *
     * Some archetypes compel a regime in a jurisdiction whatever the platform configured for the rest: the German
     * profile holds goods sold between private people to be arranged, never resold. Every document of such a sale
     * follows that regime, so a posture that requires the other one would name a different seller on the receipt
     * than the books do. A document refuses that pair when it is written, which is after the money moved; this
     * refuses it before. A posture without any regime, naming the merchant as the seller, produces no document
     * chain to contradict, and an archetype nothing compels is left to the configured default.
     */
    private function assertPostureCarriesTheCompelledRegime(SellerOfRecordPosture $posture, ?TaxArchetype $archetype): void
    {
        if (! $archetype instanceof TaxArchetype || ! new RegimePostureGuard()->hasAnyRegime($posture)) {
            return;
        }

        $profile = $this->profile();
        $compelled = $profile instanceof SuppliesArchetypeRegimes ? $profile->regimeForArchetype($archetype) : null;

        if ($compelled instanceof SupplyRegime && $compelled->requiredPosture() !== $posture) {
            throw RegimeNotPermitted::archetypeCompelsAnotherPosture($archetype, $compelled, $posture);
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

    /** The jurisdiction profile in force, or null where none is configured. */
    private function profile(): ?JurisdictionProfile
    {
        return Container::getInstance()->make(CheckpointRegistry::class)->profile();
    }

    /** The bound reporting profile, typed by its contract: a host may bind one that keeps no such record. */
    private function reportingProfile(): ReportingProfile
    {
        return Container::getInstance()->make(ReportingProfile::class);
    }

    /**
     * Whether the buyer is known to buy as a business: a verified VAT number, or the application's own word.
     *
     * Anything less leaves the presumption standing that a buyer is not one (Art. 5d of Implementing Regulation
     * (EU) No 282/2011), and so does a lane that names no buyer.
     */
    private function buysAsABusiness(?Model $buyer): bool
    {
        if (! $buyer instanceof Model) {
            return false;
        }

        if (Container::getInstance()->make(IdentifiesBusinessBuyers::class)->isBusiness($buyer)) {
            return true;
        }

        $key = $buyer->getKey();

        return (is_int($key) || is_string($key))
            && new StandingTaxIdVerification()->of($buyer->getMorphClass(), $key) instanceof TaxIdVerification;
    }

    /** The import scheme number as configured, trimmed and upper-cased, or null where none is. */
    private function configuredImportSchemeNumber(): ?string
    {
        $value = Container::getInstance()->make(Repository::class)->get('billing.marketplace.deemed_supply_of_goods.ioss_number');

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null;
    }
}
