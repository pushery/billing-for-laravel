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

    /**
     * A reseller of SECOND-HAND GOODS, taxed on the difference between purchase and sale.
     *
     * The value stays `margin`, and that is the whole reason the goods class is a case here rather than a
     * column beside it: every document ever issued under this basis was a second-hand goods sale, because
     * that is the only wording the package shipped. A new column would have had to read absent as
     * second-hand, which is a guess dressed as a default; this reads the stored value as exactly what it
     * always meant.
     */
    case Margin = 'margin';

    /**
     * A reseller of WORKS OF ART, taxed on the margin.
     *
     * Its own case because its mandatory statement on the document is its own — Art. 226(14) of the VAT
     * Directive prescribes one phrase per goods class, and a document carrying the wrong one is wrong in
     * the one field an auditor reads first. The e-invoice half differs too: `VATEX-EU-I` rather than
     * `VATEX-EU-F`.
     */
    case MarginWorksOfArt = 'margin_works_of_art';

    /**
     * A reseller of COLLECTORS' ITEMS AND ANTIQUES, taxed on the margin.
     *
     * The third class the directive names, with its own phrase and `VATEX-EU-J`. Kept together rather than
     * split in two, because the directive itself names them as one class.
     */
    case MarginCollectorsItems = 'margin_collectors_items';

    /** Not a business at all — an occasional private seller, who owes nothing and issues nothing. */
    case Private = 'private';

    /**
     * Whether tax is stated on the document at all.
     *
     * ASKED THROUGH `taxesMarginOnly()` RATHER THAN BY LISTING THE MARGIN CASES, and this line is the
     * "fifth place" the note below predicts. It read `=== self::Margin` until the directive's other two
     * goods classes arrived; spelled that way it would have answered NO for a work of art, and a margin
     * document that states no tax at all is a different document from one that states none because the
     * scheme forbids it.
     */
    public function statesTax(): bool
    {
        return $this === self::Standard || $this->taxesMarginOnly();
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
        return in_array($this, [self::Margin, self::MarginWorksOfArt, self::MarginCollectorsItems], true);
    }

    /**
     * Which goods class this margin basis is about, as the suffix its wording and its VATEX code are keyed on.
     *
     * Null for a basis that is not margin-taxed at all, which is the ordinary case and not a failure — a
     * caller asks this only after `taxesMarginOnly()` has said yes, and a null here means the question did
     * not apply rather than that the class is unknown.
     */
    public function marginGoodsClass(): ?string
    {
        return match ($this) {
            self::Margin => 'second_hand_goods',
            self::MarginWorksOfArt => 'works_of_art',
            self::MarginCollectorsItems => 'collectors_items',
            default => null,
        };
    }
}
