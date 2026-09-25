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

    /**
     * A payment frozen as no consideration at all, on a document that also states tax.
     *
     * A late fee is the case: it compensates a delay and buys nothing, so category O applies, and O is exclusive.
     */
    public static function taxedPaymentOutsideTheScope(float $rate): self
    {
        return new self(
            'This document is frozen as a payment that is not the consideration for any supply, such as a late fee '
            ."— EN 16931 category O, outside the scope of VAT — but carries a band taxed at {$rate}%. Category O is "
            .'exclusive (BR-O-11) and may not state a tax rate or amount. The payment belongs on its own document.'
        );
    }

    /**
     * A document frozen as taxed on the margin that also names an exemption.
     *
     * A margin-taxed supply is not exempt: the tax is due and contained in the margin, and only stating it is
     * forbidden. Rendered either way, the document would drop one of its own two statements without saying so.
     */
    public static function marginSchemeWithExemption(): self
    {
        return new self(
            'This document is frozen as taxed on the margin and also names an exemption. A margin-taxed supply '
            .'is not exempt: the tax is due, contained in the margin, and only stating it is forbidden. One of '
            .'the two is wrong. Correct the record rather than issuing a document that claims both.'
        );
    }

    /**
     * A document frozen as taxed on the margin whose lines carry a tax rate.
     *
     * Naming a rate on such a document is itself a statement of tax, which the seller then owes on top of the
     * tax on the margin. Refused rather than rendered with the rate dropped: the rate came from the document's
     * own lines, and dropping it quietly would hide which of the two statements is wrong.
     */
    public static function taxedMarginSupply(float $rate): self
    {
        return new self(
            "This document is frozen as taxed on the margin but carries a band taxed at {$rate}%. Naming a rate "
            .'on a margin-taxed document is itself a statement of tax, which the seller would owe on top of the '
            .'tax on the margin. Issue it without a rate.'
        );
    }
}
