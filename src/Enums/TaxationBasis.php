<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * On what basis a seller was taxed when a transaction happened.
 *
 * ## Why it is a fact on the transaction and not a lookup
 *
 * A seller's basis moves — they cross a threshold, they register, they stop trading. The document has to
 * state the basis that applied **when the supply happened**, because that is what makes it right or wrong,
 * and re-deriving it later would quietly restate old documents every time a seller's situation changed.
 *
 * ## Why three cases and not a flag
 *
 * "Business or not" is the distinction that cannot carry this. A private seller owes nothing; a small
 * business is a business that owes nothing; a margin-taxed reseller is a business that owes tax on the
 * margin alone. Collapsed into a boolean, the second and third look identical to the first right up to the
 * point where a receipt has to be produced — and then there is nothing left to decide it from.
 *
 * Nothing here names a statute or a country. Which thresholds put a seller in which case is a profile's
 * knowledge; this is only the vocabulary the document freezes.
 */
enum TaxationBasis: string
{
    /** Taxed on the full consideration, the ordinary case. */
    case Standard = 'standard';

    /** A business relieved of charging tax by a size threshold. */
    case SmallBusiness = 'small_business';

    /** A reseller taxed on the difference between purchase and sale, not on the sale. */
    case Margin = 'margin';

    /** Not a business at all — an occasional private seller, who owes nothing and issues nothing. */
    case Private = 'private';

    /** Whether tax is stated on the document at all. */
    public function statesTax(): bool
    {
        return $this === self::Standard || $this === self::Margin;
    }

    /**
     * Whether the amount taxed is the margin rather than the consideration.
     *
     * Kept as a question rather than a comparison at call sites: a caller writing `=== Margin` today writes
     * the same comparison in five places, and the fifth is the one that gets missed when a jurisdiction adds
     * a second margin-style basis.
     */
    public function taxesMarginOnly(): bool
    {
        return $this === self::Margin;
    }
}
