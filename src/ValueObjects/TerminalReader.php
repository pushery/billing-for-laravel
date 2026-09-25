<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A card reader as the provider reported it when it was asked.
 *
 * The country is where the reader stands, and it is the place of every sale made on it: for a Stripe reader the
 * country of its location's address, for a Mollie terminal the one the host declared. A reader without one cannot
 * take a sale.
 */
final readonly class TerminalReader
{
    public function __construct(
        public string $id,
        public ?string $label,
        /** Where the provider files the reader: a Stripe location, or the Mollie profile the terminal belongs to. */
        public ?string $location,
        /** The ISO 3166 code of the country the reader stands in, upper case, or null where none is known. */
        public ?string $country,
        /**
         * Whether the provider reports the reader as able to take a payment. Stripe says whether it can reach the
         * reader now. Mollie says only whether a terminal is activated, so an activated terminal that lost its
         * connection reads as online, and a sale put on it fails within thirty seconds.
         */
        public bool $online,
        /**
         * Whether the reader is taking a payment now, and would refuse a second one. Mollie does not report it, so
         * for a Mollie terminal it says whether the last sale the package put on the terminal is still open.
         */
        public bool $busy,
        public string $deviceType,
    ) {}

    /** Whether a sale put on the reader now would reach it and could be placed. */
    public function canTakeASale(): bool
    {
        return $this->online && ! $this->busy && $this->country !== null;
    }
}
