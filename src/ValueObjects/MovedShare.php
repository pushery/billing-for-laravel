<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What the provider says about a transfer that already happened — read back, not requested.
 *
 * The distinction from `TransferResult` is the whole reason this exists. That one is the answer to "move
 * this", captured once at the moment of the call. This one is the answer to "what is this transfer NOW",
 * and the two disagree in exactly the cases reconciliation is for: a reversal raised at the provider that
 * never reached the local row, a transfer the provider adjusted afterwards, a reference the local journal
 * carries and the provider has no record of.
 *
 * `reversedMinor` is cumulative, because that is how a provider reports it — a transfer reversed twice has
 * one figure, not two events to add up. Reading it as an increment would double-count a redelivered webhook
 * and report a clawback as complete while money is still with the merchant.
 */
final readonly class MovedShare
{
    public function __construct(
        public string $reference,
        /** The gross the provider moved, before any reversal. */
        public Money $moved,
        /** Cumulative reversal against that transfer, as the provider reports it. Zero if none. */
        public Money $reversed,
    ) {}

    /** What the merchant is left holding from this transfer, per the provider. */
    public function net(): Money
    {
        return $this->moved->minus($this->reversed);
    }
}
