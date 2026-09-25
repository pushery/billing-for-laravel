<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A card reader as the provider reported it when it was asked.
 *
 * The country is the country of the location the reader stands at. It is the place of every sale made on the
 * reader, so a reader at no location, or at one without an address, has no country and cannot take a sale.
 */
final readonly class TerminalReader
{
    public function __construct(
        public string $id,
        public ?string $label,
        public ?string $location,
        /** The ISO 3166 code of the location's country, upper case, or null where the reader stands at none. */
        public ?string $country,
        /** Whether the provider can reach the reader now. */
        public bool $online,
        /** Whether the reader is taking a payment now, and would refuse a second one. */
        public bool $busy,
        public string $deviceType,
    ) {}

    /** Whether a sale put on the reader now would reach it and could be placed. */
    public function canTakeASale(): bool
    {
        return $this->online && ! $this->busy && $this->country !== null;
    }
}
