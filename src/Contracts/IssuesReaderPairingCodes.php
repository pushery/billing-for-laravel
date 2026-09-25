<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ReaderPairingCode;

/**
 * Issues a code that a merchant types into a terminal to pair it, the way Mollie pairs.
 *
 * The terminal is paired with the profile the code was issued for, and it appears among that profile's terminals
 * once the code has been entered. A driver whose provider pairs the other way round binds `PairsReadersByTheirCode`
 * instead, and binds nothing here.
 */
interface IssuesReaderPairingCodes
{
    /** A new code for the profile, valid until it expires, with an image of it where the provider sends one. */
    public function requestPairingCode(string $profile): ReaderPairingCode;
}
