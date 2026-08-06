<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which quantity a platform holds fixed when the same thing is sold into markets with different tax rates.
 *
 * Something has to move. The buyer's price, the creator's payout and the tax cannot all stay put when the
 * rate changes, and choosing which one gives is a commercial decision rather than a technical one.
 */
enum PricingMode: string
{
    /**
     * One price everywhere; the payout absorbs the difference.
     *
     * The default, because a price that changes by country is visible to buyers and hard to explain. The
     * creator sees the variation per position on their statement rather than as an unexplained total.
     */
    case UniformGross = 'uniform_gross';

    /**
     * One payout everywhere; the buyer's price absorbs the difference.
     *
     * Predictable for the creator, at the cost of the same item costing different amounts in different
     * countries — which some markets treat as an ordinary fact and others do not.
     */
    case UniformPayout = 'uniform_payout';
}
