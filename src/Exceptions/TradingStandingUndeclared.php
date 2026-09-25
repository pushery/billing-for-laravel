<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A seller who has sold goods regularly this year was about to sell more without saying whether they trade as
 * a business.
 *
 * Thrown BEFORE any provider call, like the other seller-side refusals. Selling regularly makes somebody a
 * business whether or not they earn anything by it, and what follows from that (the documents of a sale,
 * the buyer's rights) depends on the answer. So the platform asks once the activity threshold is reached,
 * and the next sale of goods waits for the answer rather than being made on a guess.
 *
 * **What to do about it is not "retry".** The seller declares their standing through the platform's own
 * declaration flow, as a business or again as a private individual, and the sale goes through. A declaration
 * made before the threshold was reached does not answer it: it was a statement about a seller who had not yet
 * sold this much.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class TradingStandingUndeclared extends RuntimeException
{
    public static function forMerchant(CarbonImmutable $reachedOn): self
    {
        return new self(
            'This seller reached the activity threshold for goods on '.$reachedOn->toDateString().' and has not '
            .'declared since whether they sell as a business. The sale is refused until they do; record the '
            .'declaration through your declaration flow and the sale goes through.'
        );
    }
}
