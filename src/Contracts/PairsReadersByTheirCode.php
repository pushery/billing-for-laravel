<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\TerminalReader;

/**
 * Pairs a card reader by the code it shows on its screen, the way Stripe Terminal pairs.
 *
 * The reader is claimed for a location, and the location's address is where every sale on it is placed. A driver
 * whose provider pairs the other way round binds `IssuesReaderPairingCodes` instead, and binds nothing here.
 */
interface PairsReadersByTheirCode
{
    /**
     * Pairs a reader with a location, by the registration code the reader shows on its screen.
     *
     * @param  string  $location  the provider's location the reader will stand at
     */
    public function pairReader(string $registrationCode, string $location, ?string $label = null): TerminalReader;
}
