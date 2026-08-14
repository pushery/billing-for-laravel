<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a withdrawal comes to: what was paid, what is kept for the part already provided, what goes back.
 *
 * All three, rather than just the refund, because the two figures answer to different people. The buyer is
 * told what they get back; the books need what was retained, and it is revenue for a supply that really
 * was made rather than a discount on one that was not.
 *
 * ## They add up exactly, and that is a property rather than a coincidence
 *
 * The retained value is rounded ONCE and the refund is the difference. Rounding both independently would
 * produce two figures that miss the payment by a cent — on every withdrawal, in a direction nobody chose.
 * `29.75 = 6.94 + 22.81` is not an illustration here; it is the invariant.
 */
final readonly class WithdrawalSettlement
{
    public function __construct(
        /** What the buyer paid for the period. */
        public Money $paid,
        /** Value for the part already provided — kept, and revenue for a supply that was made. */
        public Money $retained,
        /** What goes back to the buyer: the difference, never its own rounding. */
        public Money $refundable,
    ) {}

    /** Whether any money actually moves. A period used in full owes nothing back and is not a refund. */
    public function movesMoney(): bool
    {
        return $this->refundable->minorUnits > 0;
    }
}
