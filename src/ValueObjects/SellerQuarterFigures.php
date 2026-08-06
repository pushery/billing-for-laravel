<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The three figures a reporting record asks of one seller for one quarter.
 *
 * Three, not one, and not derivable from each other. The gross inflow is what reached the seller; the fee
 * is what the platform kept; the count is how many settlements there were. A record that carried the value
 * and let a reader infer the count would drop every quarter whose movements netted to nothing — a quarter
 * with a sale and its reversal really did have two transactions.
 *
 * And the fee is NOT the gross inflow minus a payout. That derivation is right for one unmixed sale at one
 * rate and wrong for a basket that mixes rates, for a fee with a flat component, and for any quarter
 * holding both — wrong quietly, because both inputs are correct.
 */
final readonly class SellerQuarterFigures
{
    public function __construct(
        /** 1 through 4. Carried on the object so a quarter cannot be identified only by its position in a list. */
        public int $quarter,
        /** What actually reached the seller, including their own tax where they charge it. */
        public Money $grossInflow,
        /** How many settlements the quarter held — its own figure, never a count of non-zero amounts. */
        public int $transactions,
        /** What the platform kept out of those sales, net of what a refund gave back. */
        public Money $feesWithheld,
    ) {}
}
