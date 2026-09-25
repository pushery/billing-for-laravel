<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A code the provider issued for pairing a terminal, for the merchant to type into it.
 *
 * The code pairs a terminal with the profile it was issued for, once, and only until it expires. Show it to the
 * person holding the terminal; the terminal then appears among the profile's terminals.
 */
final readonly class ReaderPairingCode
{
    public function __construct(
        /** The provider's id for the code, which a revocation names. */
        public string $id,
        /** What the merchant types into the terminal. */
        public string $code,
        /** The provider's profile a terminal paired with this code belongs to. */
        public string $profile,
        /** When the code stops pairing, or null where the provider did not say. */
        public ?CarbonImmutable $expiresAt,
        /** The code as an image the host can show beside it, as a data URI, or null where the provider sent none. */
        public ?string $qrCode,
    ) {}
}
