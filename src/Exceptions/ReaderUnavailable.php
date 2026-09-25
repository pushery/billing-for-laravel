<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A sale could not be put on a card reader.
 *
 * Refused before anything is created at the provider, so a refused sale leaves no payment behind that somebody
 * would later have to cancel. A reader that is offline cannot be reached at all: the path is driven from the
 * server, and there is no copy on the reader that could take the card and pass the payment on later.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ReaderUnavailable extends RuntimeException
{
    public static function offline(string $reader): self
    {
        return new self("The reader '{$reader}' is offline, so no sale can reach it. Bring it back online, or take the sale on another reader.");
    }

    public static function busy(string $reader): self
    {
        return new self("The reader '{$reader}' is already taking a payment. Wait for it to finish, or cancel it first.");
    }

    public static function withoutCountry(string $reader): self
    {
        return new self("The reader '{$reader}' stands at no location with a country, and a sale at the counter is taxed in the country it is made in. Pair the reader with a location that has an address.");
    }

    public static function inactive(string $reader): self
    {
        return new self("The terminal '{$reader}' is not activated at its provider, so it takes no payment. Activate it, or take the sale on another terminal.");
    }

    public static function withoutDeclaredCountry(string $reader): self
    {
        return new self("No country is declared for the terminal '{$reader}', and a sale at the counter is taxed in the country it is made in. Mollie reports no address for a terminal, so name the country it stands in under billing.mollie.terminal_countries, by the terminal's id or by its profile's.");
    }
}
