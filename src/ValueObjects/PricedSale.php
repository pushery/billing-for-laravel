<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A sale priced from what the creator wants to be paid.
 *
 * Every figure here is stored rather than recomputed by whoever displays it, because they must agree: the
 * receipt, the ledger and the tax return all read the same rounded values, and a second calculation
 * somewhere downstream is how three views of one sale stop matching.
 *
 * `merchantPayout` is the answer, and `targetPayout` is the question. They differ by a cent on some sales,
 * and that is a property of the decided rounding order rather than a defect — see `hitsTarget()`.
 */
final readonly class PricedSale
{
    public function __construct(
        /** What the creator asked to be paid. */
        public Money $targetPayout,
        /** What the buyer pays, tax included. */
        public Money $fanGross,
        /** The sale without tax — what the commission is taken from. */
        public Money $transactionNet,
        /** The tax, computed as the difference so the two halves sum back to the gross exactly. */
        public Money $tax,
        /** What the platform keeps. */
        public Money $platformFee,
        /** What the creator is actually paid. */
        public Money $merchantPayout,
    ) {}

    /**
     * Whether the creator is paid exactly what they asked for.
     *
     * Often false by a single cent, and deliberately so: with the residual cent going to the platform, a
     * target that does not divide evenly cannot be hit. Only the creator-first order lands on it exactly,
     * at the cost of that cent on every uneven sale.
     *
     * The consequence is a rule for whoever builds the screen: show the RESULTING payout. Treating the
     * input as a promise makes an off-by-one-cent look like a bug and invites somebody to "fix" it by
     * rounding a second time, which is how the sale stops adding up.
     */
    public function hitsTarget(): bool
    {
        return $this->merchantPayout->equals($this->targetPayout);
    }
}
