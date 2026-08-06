<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a provider said about taking a merchant's share back.
 *
 * The mirror of {@see TransferResult}, and minimal for the same reasons: the reference the reversal got, and
 * the amount that actually came back. Neither is derivable from the request — a provider can reverse less
 * than was asked for, most obviously when part of the transfer has already been reversed, and the reference
 * is what a later reconciliation matches against.
 *
 * REPORTING WHAT WAS ASKED FOR RATHER THAN WHAT MOVED is the failure this shape exists to prevent, and it is
 * worse on this side than on the outbound one. A ledger that records the requested figure believes the
 * clawback is complete, so it never asks for the rest — and the difference sits with the merchant while
 * every total adds up.
 *
 * There is no "pending" here either. A reversal either happened or the call failed; a driver that cannot say
 * which must throw rather than return something that reads as success with nothing behind it.
 */
final readonly class TransferReversal
{
    public function __construct(
        public string $reference,
        public Money $reversed,
    ) {}
}
