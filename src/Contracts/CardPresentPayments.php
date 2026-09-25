<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Exceptions\ReaderUnavailable;
use Pushery\Billing\ValueObjects\InPersonCollection;
use Pushery\Billing\ValueObjects\InPersonSale;
use Pushery\Billing\ValueObjects\TerminalReader;

/**
 * Takes a payment in person, on a card reader that stands somewhere.
 *
 * This is a second payment path beside the remote one, not a variant of it: a reader has a state of its own, and
 * every sale made on it is placed where it stands. A driver whose provider runs readers implements this, and only
 * such a driver binds it, so a host asks the container whether it is bound before it offers a sale at the counter.
 *
 * Pairing a reader is not part of it, because the providers pair in opposite directions. At Stripe the reader
 * shows a code and the server claims it (`PairsReadersByTheirCode`); at Mollie the server issues a code and the
 * merchant types it into the terminal (`IssuesReaderPairingCodes`). A driver binds the one its provider speaks.
 *
 * The path takes cards and nothing else. There is no way to settle part of a sale with a credit balance, a voucher
 * or a gift card on it, and that absence is what keeps the package from being a cash register. A system that
 * accepts any of them at the counter records payments that count as cash, and needs a certified security module
 * the package does not have.
 */
interface CardPresentPayments
{
    /** A reader as the provider reports it now: where it stands, and whether it can take a payment. */
    public function reader(string $reader): TerminalReader;

    /**
     * Puts a sale on a reader, for the buyer to pay with a card.
     *
     * The sale is taxed in the country the reader stands in, and the reader shows its gross. The money moves when
     * the buyer presents a card, which this call does not wait for. The provider reports the outcome.
     *
     * @throws ReaderUnavailable when the reader is known not to be able to take the sale: offline or not
     *                           activated, already taking a payment, or standing in no country the package knows
     */
    public function collect(string $reader, InPersonSale $sale): InPersonCollection;

    /** Takes a sale off a reader before the buyer has paid it. */
    public function cancel(string $reader): void;
}
