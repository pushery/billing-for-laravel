<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Exceptions\ReaderUnavailable;
use Pushery\Billing\ValueObjects\InPersonCollection;
use Pushery\Billing\ValueObjects\InPersonSale;
use Pushery\Billing\ValueObjects\TerminalReader;

/**
 * Takes a payment in person, on a card reader that stands at a location.
 *
 * This is a second payment path beside the remote one, not a variant of it: a reader is paired with a location, it
 * has a state of its own, and every sale made on it is placed where it stands. A driver whose provider runs readers
 * implements this, and only such a driver binds it. A driver without readers (Mollie) binds nothing, so a host asks
 * the container whether it is bound before it offers a sale at the counter.
 *
 * The path takes cards and nothing else. There is no way to settle part of a sale with a credit balance, a voucher
 * or a gift card on it, and that absence is what keeps the package from being a cash register. A system that
 * accepts any of them at the counter records payments that count as cash, and needs a certified security module
 * the package does not have.
 */
interface CardPresentPayments
{
    /**
     * Pairs a reader with a location, by the registration code the reader shows on its screen.
     *
     * @param  string  $location  the provider's location the reader will stand at
     */
    public function pairReader(string $registrationCode, string $location, ?string $label = null): TerminalReader;

    /** A reader as the provider reports it now: where it stands, and whether it can take a payment. */
    public function reader(string $reader): TerminalReader;

    /**
     * Puts a sale on a reader, for the buyer to pay with a card.
     *
     * The sale is taxed in the country of the reader's location, and the reader shows its gross. The money moves
     * when the buyer presents a card, which this call does not wait for. The provider reports the outcome.
     *
     * @throws ReaderUnavailable when the reader is offline, already taking a payment, or stands at no location
     *                           with a country
     */
    public function collect(string $reader, InPersonSale $sale): InPersonCollection;

    /** Takes a sale off a reader before the buyer has paid it. */
    public function cancel(string $reader): void;
}
