<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\TaxRateCategory;
use RuntimeException;

/**
 * The platform's own supply of goods to the buyer, in a sale it is treated as making itself, cannot be taxed.
 *
 * That supply is taxed in the member state where the transport ends, at a rate of that member state's table, and
 * its document states that tax. Where the facts handed in contradict the classification, place the supply outside
 * the Union or ask for a rate band no table answers for that member state, there is no tax to state that anybody
 * could defend, so the supply is refused rather than taxed at a guess or at zero.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DeemedSupplyOfGoodsRefused extends RuntimeException
{
    /** An imported consignment worth more than the 150 euros Art. 14a(1) VAT Directive reaches. */
    public static function importAboveTheThreshold(int $intrinsicValueMinor): self
    {
        $value = number_format($intrinsicValueMinor / 100, 2, '.', '');

        return new self(
            "An imported consignment of an intrinsic value of {$value} EUR is above the 150 euros of Art. 14a(1) VAT "
            .'Directive, so the platform is not treated as supplying it. Its import is declared at customs, not under '
            .'the import scheme, and no document of a deemed supply is issued for it.'
        );
    }

    /** Goods subject to excise duty, which the import scheme does not take. */
    public static function importedExciseGoods(): self
    {
        return new self(
            'Goods subject to excise duty, such as alcohol, tobacco or energy products, are excluded from the distance '
            .'sales of imported goods and from the import scheme, so the platform is not treated as supplying an '
            .'imported consignment of them under Art. 14a(1) VAT Directive.'
        );
    }

    /** Goods distance sales do not cover: a new means of transport, or goods supplied installed or assembled. */
    public static function outsideDistanceSales(bool $newMeansOfTransport): self
    {
        $goods = $newMeansOfTransport
            ? 'A new means of transport is'
            : 'Goods supplied after installation or assembly are';

        return new self(
            "{$goods} excluded from distance sales (Art. 14(4) VAT Directive), and this package does not treat the "
            .'platform as supplying them under Art. 14a. Their supply is taxed by rules of its own, so it is refused '
            .'rather than taxed as a distance sale.'
        );
    }

    /** A consignment whose origin contradicts the paragraph the sale was classified under. */
    public static function consignmentContradictsTheSupply(DeemedSupplyOfGoods $supply): self
    {
        $consignment = $supply === DeemedSupplyOfGoods::ImportedConsignment
            ? 'whose transport begins inside the Union'
            : 'whose transport begins outside the Union';

        return new self(
            "The sale was classified under {$supply->provision()}, but the consignment passed is one {$consignment}. "
            .'The two describe different supplies with different places of supply, so the tax of neither can be '
            .'stated. Classify the sale from the consignment it ships in.'
        );
    }

    /** A deemed supply of goods already in the Union that does not say which member state they are in. */
    public static function goodsLocationUnknown(?string $country): self
    {
        $named = $country === null || $country === '' ? 'none was passed' : "[{$country}] was passed";

        return new self(
            'The supply of goods already in the Union to the buyer is taxed where the transport ends, and it is a '
            .'distance sale only where the goods leave the member state they are in. That needs the member state '
            ."the goods were in when the transport began, as an ISO 3166 code of a member state, and {$named}. "
            .'Pass it as `goodsLocatedIn`.'
        );
    }

    /** A transport that does not end in a member state, which no deemed supply of goods has. */
    public static function destinationOutsideTheUnion(?string $country): self
    {
        $named = $country === null || $country === '' ? 'No destination was passed' : "[{$country}] is not a member state";

        return new self(
            'The platform is treated as supplying goods only where they are sold to a buyer in the Union, because the '
            ."supply is taxed in the member state where the transport ends. {$named}. A sale to a buyer outside the "
            .'Union is an export, which is not a deemed supply.'
        );
    }

    /**
     * A sale whose caller priced it at another rate than the one the platform's supply is taxed at.
     *
     * The rate a routed sale is charged at decides the commission's base and the buyer's document, and for a deemed
     * supply that rate is the one the decision places the supply at. Charged at another, the document would state a
     * tax the buyer was never charged, so the sale is refused before any money moves.
     */
    public static function rateDiffersFromTheDecision(int $passedBps, int $decidedBps, string $taxedIn): self
    {
        return new self(
            "The platform's supply of these goods is taxed in [{$taxedIn}] at {$decidedBps} basis points, and the sale "
            ."was priced at {$passedBps}. Ask DeemedSupplyOfGoodsTaxDecision::decide() for the rate before the sale and "
            .'charge it at that rate.'
        );
    }

    /** A rate band the rate tables carry no rate for in this member state. */
    public static function rateCategoryUnanswered(string $country, TaxRateCategory $category): self
    {
        return new self(
            "No rate table answers the [{$category->value}] band for [{$country}]. The shipped table carries every "
            .'member state\'s standard rate and no other band, so a reduced band is answered only by billing.tax_matrix '
            ."or by the rates of the active tax profile, and neither covers [{$country}]. Configure its bands in "
            .'billing.tax_matrix, or sell the goods at the standard rate. Taxing them at a rate nobody configured, '
            .'or at none, would state a tax nobody can defend.'
        );
    }
}
