<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Tax\DeemedSupplyOfGoodsTaxDecision;

/**
 * How the platform's own supply of goods to the buyer is taxed, in a sale it is treated as making itself.
 *
 * A platform treated as the supplier (Art. 14a VAT Directive) receives the goods from the seller and supplies them
 * to the buyer at the full price. This is the second of those two supplies: where it is taxed, at which rate, and
 * the split of what the buyer paid into the net and the tax. It is decided once, by
 * {@see DeemedSupplyOfGoodsTaxDecision}, and the buyer's document is written from it.
 *
 * The amounts are the buyer's whole payment, split at the rate: the platform's turnover is the price, not a fee.
 */
final readonly class DeemedSupplyOfGoodsTax
{
    public function __construct(
        /** The paragraph that makes the platform the supplier. */
        public DeemedSupplyOfGoods $supply,
        /** The member state whose tax the supply carries, as an ISO 3166 code. */
        public string $taxedIn,
        /** Where the supply is taxed: at the buyer, or at the platform below the distance-sale threshold. */
        public PlaceOfSupplyRule $rule,
        /** The band of that member state's rates the goods fall in. */
        public TaxRateCategory $rateCategory,
        /** The rate in basis points. */
        public int $rateBps,
        /** What the buyer paid, tax included. */
        public Money $gross,
        public Money $net,
        public Money $tax,
        /** The member state where the transport ends, which is where the buyer is. */
        public string $destinationCountry,
        /** The member state the goods were in when the transport began, for goods already in the Union. */
        public ?string $goodsLocatedIn,
        /** When the payment was accepted, at which the tax on both supplies arises (Art. 66a VAT Directive). */
        public CarbonImmutable $taxPoint,
    ) {}

    /**
     * Whether the goods travel into the buyer's member state from outside it.
     *
     * An imported consignment always does, and goods already in the Union do when they leave the member state they
     * are in. Goods sold within one member state are no distance sale: their supply is a domestic one there.
     */
    public function distanceSale(): bool
    {
        return $this->supply === DeemedSupplyOfGoods::ImportedConsignment
            || $this->goodsLocatedIn !== $this->destinationCountry;
    }

    /**
     * The tax characteristics the buyer's document freezes.
     *
     * The destination stays the buyer's member state where the platform's rate applies below the threshold: the
     * column names where the buyer is, and the threshold counts the sale by it. The date of supply is the tax point,
     * the moment both supplies are taxed at, as the seller's document of its supply states it too.
     *
     * A supply of goods already in the Union that is taxed at the destination is declared in the Union scheme, the
     * supply within one member state included (Art. 369b(b) VAT Directive, in Germany § 18j Abs. 1 UStG). Below the
     * threshold it is the platform's own supply at home and goes into its ordinary return. An imported consignment
     * is declared in the import scheme's return instead, which the platform files monthly.
     */
    public function characteristics(): SupplyTaxCharacteristics
    {
        return new SupplyTaxCharacteristics(
            archetype: TaxArchetype::ConsumerGoods,
            placeOfSupply: $this->rule,
            rateCategory: $this->rateCategory,
            deliveredOn: $this->taxPoint,
            destinationCountry: $this->destinationCountry,
            deemedSupply: $this->supply,
            goodsLocatedIn: $this->goodsLocatedIn,
            oneStopShop: $this->supply === DeemedSupplyOfGoods::WithinTheUnion && $this->rule === PlaceOfSupplyRule::Destination,
            importScheme: $this->supply === DeemedSupplyOfGoods::ImportedConsignment,
        );
    }
}
