<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A reverse-charged sale to a business in another member state that names no VAT id for its buyer.
 *
 * A recapitulative statement is a list of VAT ids with an amount beside each. A sale without one has no place
 * on it, and leaving it out would make the statement short with nothing looking wrong, so the export stops
 * and names the documents instead.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class RecapitulativeStatementIncomplete extends RuntimeException
{
    /** @param  list<string>  $documents  the numbers of the sales without a VAT id */
    public static function withoutVatId(array $documents): self
    {
        return new self(
            count($documents).' reverse-charged sale(s) to a business in another member state name no VAT id '
            .'for the buyer: '.implode(', ', $documents).'. A recapitulative statement lists VAT ids, so these '
            .'cannot be put on it, and leaving them out would make it short with nothing looking wrong. '
            .'Establish each buyer\'s VAT id, which the reverse charge rests on, and report these sales with it.'
        );
    }
}
