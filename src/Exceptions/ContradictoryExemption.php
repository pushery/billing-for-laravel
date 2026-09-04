<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A document states an exemption its own other fields disprove.
 *
 * Thrown rather than rendered, because the alternative is a document that asserts two incompatible things at
 * once. Whichever of the two fields is wrong, one of them is — and a reader has no way to know which, so the
 * exemption claim cannot be trusted and neither can the destination. A refusal names the conflict while
 * somebody can still fix its cause; a rendered document buries it until an audit.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ContradictoryExemption extends RuntimeException
{
    public static function exportInsideTheUnion(string $country): self
    {
        return new self(
            "This document is frozen as supplied outside the union, but states [{$country}] as its destination, "
            .'which is a member of it. One of the two is wrong. Correct the record rather than issuing a '
            .'document whose exemption its own destination disproves.'
        );
    }

    /**
     * A service frozen as outside the scope of VAT, on a document that also states tax.
     *
     * EN 16931 category `O` is exclusive (BR-O-11) and the BR-O-* rules forbid such a document stating a
     * tax amount or a rate at all. So this is not a category to soften but a document that cannot exist:
     * a conformant validator rejects it outright, and an invalid invoice is worse than an imprecise one
     * because it cannot be filed.
     *
     * Refused rather than downgraded to `Z`. A downgrade would file a supply the platform froze as outside
     * the scope of tax as though the tax had reached it — the very statement the freeze exists to prevent.
     */
    public static function taxedSupplyOutsideTheScope(float $rate): self
    {
        return new self(
            'This document is frozen as a service supplied outside the union — EN 16931 category O, outside '
            ."the scope of VAT — but carries a band taxed at {$rate}%. Category O is exclusive (BR-O-11) and "
            .'may not state a tax rate or amount, so this cannot be issued as one document. Split it: the '
            .'out-of-scope service on its own document, the taxed supply on another.'
        );
    }
}
