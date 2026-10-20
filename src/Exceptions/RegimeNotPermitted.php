<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use RuntimeException;

/**
 * A supply regime was resolved that the platform has not opted into, or that contradicts who the receipt
 * says is selling.
 *
 * Refused rather than reconciled, because the two are one decision seen twice: a platform cannot be
 * reselling in its own name on the receipt and merely arranging the sale on its books. Letting the pair
 * disagree would produce a receipt and a settlement document that describe different transactions — both
 * internally consistent, and only comparable by somebody who thought to compare them.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class RegimeNotPermitted extends RuntimeException
{
    public static function notAllowed(string $regime): self
    {
        return new self(
            "The supply regime [{$regime}] is not one this platform has opted into. A regime decides which "
            .'documents a sale produces and whose turnover it is, so it is opted into deliberately — add it '
            .'to billing.marketplace.regime.allowed only when you mean it.'
        );
    }

    public static function contradictsPosture(SupplyRegime $regime, SellerOfRecordPosture $posture): self
    {
        return new self(
            "The supply regime [{$regime->value}] requires the seller posture [{$regime->requiredPosture()->value}], "
            ."but [{$posture->value}] was resolved. These are one decision seen twice — the regime is how the "
            .'books read, the posture is who the receipt names — so a pair that disagrees would issue a '
            .'receipt and a settlement document describing different transactions.'
        );
    }

    public static function postureHasNoRegime(SellerOfRecordPosture $posture): self
    {
        return new self(
            "The seller posture [{$posture->value}] has no supply regime in this profile. Naming the merchant "
            .'as the seller is neither the platform reselling nor the platform arranging, so no document chain '
            .'follows from it here. A jurisdiction where it does binds its own profile.'
        );
    }

    /**
     * A goods leg the platform only arranged, about to be booked to an account that does not hold money passing
     * through, most often the platform's own income.
     *
     * Worth its own message because the symptom is not a wrong classification: the accounts that hold
     * revenue commonly apply a tax rate by themselves, so the booking invents tax nobody charged and nobody
     * collected. And the amount is the whole sale, against a platform that earned a few percent of it.
     */
    public static function goodsAsRevenueUnderIntermediation(string $transaction): self
    {
        return new self(
            "A goods leg cannot be booked as \"{$transaction}\" in an intermediated sale: the platform never "
            .'owned the goods, so the money is passing through it rather than being earned by it, and only an '
            .'account for money passing through takes it. A revenue '
            .'account would make the whole sale the platform\'s turnover and, on an account that applies a '
            .'rate by itself, invent tax nobody charged. Book it as a transit item.'
        );
    }

    /**
     * Goods of a seller established outside the Union, about to be recorded as a sale the platform only
     * arranged.
     *
     * The rule this refuses on is the one that makes a marketplace the deemed supplier of those goods
     * (Art. 14a(2) of the VAT Directive). The message names what to do instead, because the operator
     * reading it chose the posture and has to change a configuration, not retry a payment.
     */
    public static function intermediatedGoodsOfASellerOutsideTheUnion(): self
    {
        return new self(
            'Goods sold by a seller established outside the Union cannot be recorded as an intermediated sale: '
            .'the platform facilitating their sale to a consumer in the Union is treated as having supplied them '
            .'itself (Art. 14a VAT Directive), so its turnover is the whole sale, not a fee. Which paragraph '
            .'applies, and whether one does, depends on the consignment, and none was passed with this sale. '
            .'Pass a GoodsConsignment so the sale can be classified.'
        );
    }

    /**
     * Goods of a seller established outside the Union, sold to a buyer who is a business.
     *
     * The platform is treated as the supplier only where the buyer is not a business, and it may assume that of
     * every buyer unless it knows otherwise (Art. 5d of Implementing Regulation (EU) No 282/2011). A verified
     * VAT number, or the application naming the owner a business, is knowing otherwise. What remains is the
     * seller's own supply in the Union, which needs their registration there.
     */
    public static function goodsOfASellerOutsideTheUnionSoldToABusiness(): self
    {
        return new self(
            'Goods of a seller established outside the Union, sold to a buyer who is a business, are the '
            .'seller\'s own supply: the platform is treated as their supplier only for a buyer who is not a '
            .'business (Art. 14a VAT Directive, Art. 5d Implementing Regulation 282/2011), and this buyer has a '
            .'verified VAT number or is named a business by the application. Documenting the seller\'s supply '
            .'would need their VAT registration in the Union, which this package does not know.'
        );
    }

    /**
     * A new vehicle, vessel or aircraft, or goods supplied after installation or assembly, from a seller outside
     * the Union.
     *
     * Both are excluded from distance sales by Art. 14(4) VAT Directive, so neither is a sale the platform is
     * treated as supplying. The flag picks the sentence, because the two follow different rules afterwards.
     */
    public static function goodsOfASellerOutsideTheUnionOutsideDistanceSales(bool $newMeansOfTransport): self
    {
        $goods = $newMeansOfTransport
            ? 'A new means of transport'
            : 'Goods supplied after installation or assembly';

        $rule = $newMeansOfTransport
            ? 'a new vehicle, vessel or aircraft is taxed where it arrives under rules of its own'
            : 'goods installed or assembled are supplied where that work is done';

        return new self(
            "{$goods} sold by a seller established outside the Union is not a sale the platform is treated as "
            ."supplying: Art. 14(4) VAT Directive excludes it from distance sales, and {$rule}. It remains the "
            .'seller\'s own supply, for which this package has no document chain.'
        );
    }

    /**
     * Goods subject to excise duty, imported for a seller established outside the Union.
     *
     * The rules for low-value imports leave them out: the import scheme excludes them (Art. 369l VAT Directive),
     * and the Commission's explanatory notes on the 2021 rules exclude them from the deemed supply of imported
     * goods with it (section 1.2). The import and the duty follow their own procedure.
     */
    public static function importedExciseGoodsOfASellerOutsideTheUnion(): self
    {
        return new self(
            'Goods subject to excise duty, imported for a seller established outside the Union, are outside the '
            .'rules for low-value imports: the import scheme excludes them (Art. 369l VAT Directive), and with it '
            .'the deemed supply of imported goods. The import and the duty follow their own procedure, and the '
            .'sale remains the seller\'s own supply, for which this package has no document chain.'
        );
    }

    /**
     * An imported consignment worth more than 150 euros, from a seller established outside the Union.
     *
     * The deemed supply of imported goods ends at that value (Art. 14a(1) VAT Directive). Above it the goods are
     * imported by whoever the terms of delivery name, and the sale is the seller's own.
     */
    public static function importedConsignmentOfASellerOutsideTheUnionAboveTheThreshold(int $intrinsicValueMinor): self
    {
        return new self(
            "An imported consignment with an intrinsic value of {$intrinsicValueMinor} euro cents is above the 150 "
            .'euros up to which the platform is treated as supplying imported goods (Art. 14a(1) VAT Directive). '
            .'The goods are imported by whoever the terms of delivery name, and the sale remains the seller\'s '
            .'own supply, for which this package has no document chain.'
        );
    }

    /**
     * A deemed supply of goods, declared as taxed on the margin.
     *
     * The platform is the supplier of a deemed supply, and the margin scheme is available only to a reseller for
     * goods supplied to it without deductible tax; the leg that would have to be that supply lies outside the
     * Union or is exempt. `MarginSchemeAvailability` holds the same line for every sale.
     */
    public static function deemedSupplyOfGoodsOnTheMargin(DeemedSupplyOfGoods $supply): self
    {
        return new self(
            "A sale the platform is treated as supplying under {$supply->provision()} cannot be taxed on the "
            .'margin: the scheme is available to a reseller for goods supplied to it without deductible tax, and '
            .'the leg that would have to be that supply lies outside the Union or is exempt. Its document would '
            .'state no tax while the full price was owed.'
        );
    }

    /**
     * An imported consignment the platform supplies, with no import scheme number configured.
     *
     * The tax on such a sale is declared under the import scheme, and the number is what the declaration and the
     * customs entry are filed under. A value that is not one, such as a VAT number pasted into the field, is no
     * number either.
     */
    public static function importedConsignmentWithoutAnImportSchemeNumber(?string $configured): self
    {
        $state = $configured === null
            ? 'is not set'
            : "holds [{$configured}], which is not one: it is IM followed by ten digits";

        return new self(
            'The platform is treated as supplying this imported consignment (Art. 14a(1) VAT Directive), and its '
            .'tax is declared under the import scheme, by the number the platform was registered under. '
            ."billing.marketplace.deemed_supply_of_goods.ioss_number {$state}."
        );
    }

    /**
     * A deemed supply of goods, classified before any money moved, under a tax profile that does not document one.
     *
     * The documents of the two legs, the returns they are declared in and the records the platform keeps as the
     * supplier are a jurisdiction's, and a profile declares that it has them by implementing
     * `DocumentsDeemedSupplyOfGoods`. Recording the sale as anything else would
     * describe a transaction that did not happen, so it is refused here, before the provider is called.
     */
    public static function deemedSupplyOfGoodsNotDocumented(DeemedSupplyOfGoods $supply): self
    {
        return new self(
            "The platform is treated as supplying these goods itself under {$supply->provision()}, so its "
            .'turnover is the whole sale, not a fee, and the sale has two legs with the platform in the middle. '
            .'The tax profile in force does not document those legs or declare them, and recording the sale as an '
            .'intermediation would describe a transaction that did not happen.'
        );
    }

    /**
     * A deemed supply of goods whose consignment does not say what it holds.
     *
     * The platform keeps records of every supply it makes in a special scheme, and they describe the goods and their
     * quantity (Art. 63c Implementing Regulation 282/2011). The buyer's document names the goods from the same
     * description, so a sale that brings none is refused before any money moves rather than documented as an
     * unnamed purchase.
     */
    public static function deemedSupplyOfGoodsUndescribed(DeemedSupplyOfGoods $supply): self
    {
        return new self(
            "The platform is treated as supplying these goods itself under {$supply->provision()}, and its records and the "
            .'buyer\'s document describe the goods and their quantity. Pass the description, and the quantity where it is '
            .'more than one, with the GoodsConsignment.'
        );
    }

    /**
     * A deemed supply of goods already in the Union, on an installation that has not declared the Union scheme.
     *
     * Declared in the Union scheme, the platform's supply to the buyer follows the invoicing rules of the member state
     * the platform is identified in, and the receipt this package issues is enough (Art. 219a VAT Directive). Outside
     * it, a distance sale would need an invoice under the rules of the member state it is taxed in, with the
     * platform's VAT number there, which this package does not issue. So the sale waits for the operator's statement
     * that the platform declares such supplies in that scheme.
     */
    public static function deemedSupplyOfGoodsWithoutTheUnionScheme(): self
    {
        return new self(
            'The platform is treated as supplying these goods already in the Union (Art. 14a(2) VAT Directive), and its '
            .'receipt to the buyer is only enough where the platform declares such supplies in the Union scheme. Set '
            .'billing.marketplace.deemed_supply_of_goods.union_scheme to true once it does.'
        );
    }

    /**
     * A sale the jurisdiction profile holds to a regime that the configured posture cannot carry.
     *
     * The profile answers for one jurisdiction: in Germany goods sold between private people are arranged, never
     * resold. The documents of such a sale follow that regime whatever the platform configured for the rest, and a
     * posture that requires the other regime would name a different seller on the receipt than the books do. The
     * message names the posture to configure, because the operator reading it chose the posture.
     */
    public static function archetypeCompelsAnotherPosture(TaxArchetype $archetype, SupplyRegime $compelled, SellerOfRecordPosture $posture): self
    {
        return new self(
            "The tax profile holds a [{$archetype->value}] sale to the supply regime [{$compelled->value}], which "
            ."requires the seller posture [{$compelled->requiredPosture()->value}], but [{$posture->value}] is "
            .'configured. The sale is refused before any money moves, because its receipt and its settlement '
            .'document would describe different transactions. Sell it under the posture the regime requires, '
            .'or not on this platform.'
        );
    }

    public static function intermediationHasNoInboundTaxMatrix(SupplyRegime $regime): self
    {
        return new self(
            "The input-side tax matrix belongs to the commission chain, but was applied to a [{$regime->value}] "
            .'sale. Intermediation has no self-billed input side — the platform arranges someone else\'s supply '
            .'and issues a real commission invoice for its own fee, so routing it through this matrix would '
            .'fabricate a settlement document for a supply that is not the platform\'s to settle.'
        );
    }
}
