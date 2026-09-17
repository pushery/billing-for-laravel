<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;

/**
 * The tax a payment provider computed for one sale, as the provider itself reports it.
 *
 * ## Why all three numbers, when two would determine the third
 *
 * Because determining it is exactly what this class exists to avoid. On a lane where the provider
 * computed the tax, every figure a document states has to be one the provider stated — a rate
 * derived from amounts, or an amount derived from a rate, is this package's arithmetic wearing the
 * provider's authority. The three are carried together so the caller can check that they agree
 * rather than trust that they must.
 *
 * The rate is here for the document, not for the split: a tax document has to name the applicable
 * rate, and the amounts beside it come from the provider unchanged.
 */
final readonly class ProviderComputedTax
{
    public function __construct(
        /** The rate the provider actually applied, in basis points. */
        public int $rateBps,
        /** The taxable base, exactly as the provider reports it. */
        public Money $net,
        /** The tax, exactly as the provider reports it. */
        public Money $tax,
    ) {
        if ($rateBps < 0) {
            throw new InvalidArgumentException("A tax rate cannot be negative; got {$rateBps}.");
        }

        if ($net->currency !== $tax->currency) {
            throw new InvalidArgumentException(
                'The base and the tax of one sale cannot be in two currencies; their sum would be in neither.'
            );
        }
    }

    /**
     * Whether this split really is the split of that gross.
     *
     * The control the caller owes itself. A reading that lost a line, took the wrong one of several,
     * or picked up a figure in a second currency still produces a plausible object — and the only
     * thing that catches it is the sum. A document whose parts do not add up to what was charged is
     * the one defect a reader notices immediately and a test rarely does.
     */
    public function accountsFor(Money $gross): bool
    {
        return $this->net->currency === $gross->currency
            && $this->net->minorUnits + $this->tax->minorUnits === $gross->minorUnits;
    }
}
