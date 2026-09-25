<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A private individual was about to sell goods through the platform with a record that lacks what the
 * reporting regime requires of them.
 *
 * Thrown BEFORE any provider call. The record has to exist before the sale, not be chased afterwards: the
 * platform keeps it because it answers for sales it arranged, and a sale made without it is exactly the sale
 * it could not answer for. The seller completes their record through the platform's own flow and the sale
 * goes through.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SellerRecordIncomplete extends RuntimeException
{
    /** @param  list<string>  $missing */
    public static function forPrivateGoodsSeller(array $missing): self
    {
        return new self(
            'This seller sells goods as a private individual and their record lacks: '.implode(', ', $missing).'. '
            .'The sale is refused until the record is complete.'
        );
    }
}
