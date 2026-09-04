<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * A buyer-chosen GROSS amount could not be split into a net and a tax the regime agrees with.
 *
 * Splitting a gross means inverting the rate, and inverting a rounded function is not free: for some totals
 * no whole-cent net reproduces the total exactly, so the split may sit one minor unit away from what the
 * calculator would charge on that net. That single cent is tolerated, because the amount the fan chose is
 * the one figure that may not move — they agreed to pay it, and a receipt whose parts do not sum to it is
 * wrong in a way a reader can see.
 *
 * A LARGER divergence is a different statement entirely: it means the rate does not scale with the amount,
 * so the rate read back from one amount does not describe another. A tiered or threshold regime behaves that
 * way. There the inversion has no answer at all, and producing one would print a plausible net on a document
 * that the return then contradicts. So it refuses instead — loudly, at the seam, rather than quietly on a
 * document nobody re-derives.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class GrossPriceNotSplittable extends RuntimeException
{
    public function __construct(
        public readonly Money $gross,
        public readonly int $rateBps,
        public readonly Money $expectedTax,
        public readonly Money $actualTax,
    ) {
        parent::__construct(
            "A chosen gross of {$gross->format()} cannot be split at {$rateBps} bps: the split implies tax of ".
            "{$expectedTax->format()}, but the regime charges {$actualTax->format()} on the resulting net. ".
            'A gap wider than a single minor unit means the rate does not scale with the amount, so no net '.
            'reproduces this total. Price this sale from a net amount instead of a buyer-chosen gross.'
        );
    }
}
