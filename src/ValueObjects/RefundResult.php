<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The outcome of a PaymentRails refund. Provider-neutral so the engine never inspects a raw
 * provider refund object.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $successful,
        public string $reference,
        public Money $amount,
        /**
         * The provider's reference for the reversal of the merchant's share, when one was made.
         *
         * Null on an unrouted refund, and also null when the policy left the money with the merchant —
         * both are real outcomes rather than failures. What must never happen is a successful refund
         * reporting a reversal that did not occur, which is why this is the provider's own reference and
         * not a flag the package sets from its own intent.
         */
        public ?string $reversedTransferReference = null,
        /**
         * How much of the merchant's share actually came back, as the PROVIDER reported it.
         *
         * A reference says a reversal happened; it does not say for how much, and the two are not the same
         * question. A partial refund reverses a partial share, and on any fee with a fixed component the
         * amount owed back is not the proportional one — so a ledger that infers the figure from the refund
         * total drifts by real money and looks right while it does. This is the provider's own number or
         * nothing.
         *
         * Null means NOT REPORTED, never zero: no reversal was made, or the provider answered with the
         * reversal's id alone. A reader that needs "how much" must branch on null rather than default it.
         */
        public ?Money $transferReversed = null,
        /**
         * How much of the platform's own fee was handed back with the refund.
         *
         * Its own dimension rather than a deduction from {@see self::$transferReversed}, because the two
         * move between different pairs of parties: the reversal takes money from the merchant, the fee
         * refund gives up the platform's margin. Netting them into one figure loses which side gave, and
         * that is exactly what a books entry has to record.
         *
         * Null here means NOT REPORTED. The shipped single-lane rails never populate it: Stripe answers a
         * refund with no per-refund fee-refund figure at all — the amount lives on the ApplicationFee as a
         * CUMULATIVE total across every refund of that charge, which for a second partial refund is a
         * different number from the one this refund caused. A lane that requests the fee refund with an
         * explicit amount knows the figure it asked for and reports that.
         */
        public ?Money $applicationFeeRefunded = null,
    ) {}

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
