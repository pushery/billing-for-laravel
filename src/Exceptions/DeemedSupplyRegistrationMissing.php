<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The seller's supply of goods to the platform that is treated as supplying them could not be documented.
 *
 * The self-billed invoice for that supply names the member state the goods were in and the seller's registration
 * there (Art. 31, Art. 226(3) VAT Directive). Without either, the document would name the wrong supply or the wrong
 * number, so it is refused before a number is drawn.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DeemedSupplyRegistrationMissing extends RuntimeException
{
    public static function goodsLocationUnknown(?string $country): self
    {
        $named = $country === null || $country === '' ? 'none was passed' : "[{$country}] was passed";

        return new self(
            'A deemed supply of goods already in the Union needs the member state the goods were in when the transport '
            ."began, as an ISO 3166 code of a member state, and {$named}. The seller's supply to the platform takes place "
            .'there, and its document names the seller\'s registration there. Pass it as `goodsLocatedIn` in the sale\'s '
            .'SupplyTaxCharacteristics.'
        );
    }

    public static function inMemberState(string $country): self
    {
        return new self(
            "The seller has no VAT registration in [{$country}] that this installation knows. A seller established "
            ."outside the Union whose goods are in [{$country}] is registered there, and the self-billed invoice for its "
            .'supply to the platform names that registration. Bind SuppliesSellerVatRegistrations to answer it, or record '
            .'the number on the seller\'s invoice party.'
        );
    }
}
