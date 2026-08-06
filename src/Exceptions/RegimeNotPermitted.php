<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SupplyRegime;
use RuntimeException;

/**
 * A supply regime was resolved that the platform has not opted into, or that contradicts who the receipt
 * says is selling.
 *
 * Refused rather than reconciled, because the two are one decision seen twice: a platform cannot be
 * reselling in its own name on the receipt and merely arranging the sale on its books. Letting the pair
 * disagree would produce a receipt and a settlement document that describe different transactions — both
 * internally consistent, and only comparable by somebody who thought to compare them.
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
     * A goods leg the platform only arranged, about to be booked as the platform's own income.
     *
     * Worth its own message because the symptom is not a wrong classification: the accounts that hold
     * revenue commonly apply a tax rate by themselves, so the booking invents tax nobody charged and nobody
     * collected. And the amount is the whole sale, against a platform that earned a few percent of it.
     */
    public static function goodsAsRevenueUnderIntermediation(string $transaction): self
    {
        return new self(
            "A goods leg cannot be booked as \"{$transaction}\" in an intermediated sale: the platform never "
            .'owned the goods, so the money is passing through it rather than being earned by it. A revenue '
            .'account would make the whole sale the platform\'s turnover and, on an account that applies a '
            .'rate by itself, invent tax nobody charged. Book it as a transit item.'
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
