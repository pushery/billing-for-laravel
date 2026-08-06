<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use InvalidArgumentException;
use Pushery\Billing\ValueObjects\Money;

/**
 * What is owed back when a prepaid term is canceled part-way through.
 *
 * A year paid in January and canceled after four months owes eight months back. The arithmetic is one line;
 * everything worth saying here is about the cent that does not divide.
 *
 * ## The cent, and why the ORDER of the allocation is the whole decision
 *
 * 119.00 over twelve months, eight of them unused, is 79.3333… — a figure no currency has. `Money::allocate()`
 * hands the indivisible remainder to the EARLIEST bucket, so which side gets it is decided by which side is
 * named first, and the two answers differ by a cent on every uneven term:
 *
 *   allocate(unused: 8, used: 4)  ->  refund 79.34, kept 39.66
 *   allocate(used: 4, unused: 8)  ->  kept   39.67, refund 79.33
 *
 * The used portion is named first, so the odd unit stays with what was KEPT. That is not a fresh choice —
 * it is the direction this package already decided for an uneven split (`RoundingResidual::ToPortion`, the
 * shipped default), applied to the same shape of question. A second rounding rule in one package is exactly
 * the divergence nobody notices, because both numbers look perfectly reasonable on their own.
 *
 * ## What this deliberately does NOT do
 *
 * It does not refund anything. It answers "how much", and the existing refund cascade does the rest —
 * § 17 Abs. 1 UStG, ex nunc, a correcting document on BOTH links of the chain. A second correction path
 * would be the one that gets forgotten at the next change in tax law, and it would be forgotten silently,
 * because the first one would still work.
 *
 * It also does not decide WHEN. The correction belongs to the period of the link it corrects, which is
 * settled elsewhere and stays settled.
 */
final readonly class ProratedTermRefund
{
    /**
     * The unused part of a prepaid term, as gross.
     *
     * @param  Money  $term  what was paid for the whole term
     * @param  int  $periodsUsed  how many of its periods the buyer had before canceling
     * @param  int  $periodsInTerm  how many periods the term was sold as
     */
    public static function unusedPortion(Money $term, int $periodsUsed, int $periodsInTerm): Money
    {
        if ($periodsInTerm < 1) {
            throw new InvalidArgumentException("A term is sold in at least one period; got {$periodsInTerm}.");
        }

        if ($periodsUsed < 0 || $periodsUsed > $periodsInTerm) {
            throw new InvalidArgumentException(
                "A term of {$periodsInTerm} period(s) cannot have {$periodsUsed} of them used. Canceling before "
                .'it starts is 0 and canceling at the end is the whole term; anything outside that is a '
                .'question about a different term.'
            );
        }

        $unused = $periodsInTerm - $periodsUsed;

        // Nothing left to give back. Said explicitly rather than left to allocate(), which refuses a zero
        // ratio set and would turn "the term ran out" into an argument error.
        if ($unused === 0) {
            return Money::zero($term->currency);
        }

        // USED FIRST. See the class docblock: allocate() gives the odd minor unit to the earliest bucket, so
        // this line is where the cent is decided, and it is decided the way the package already decides it.
        [, $refund] = $term->allocate($periodsUsed, $unused);

        return $refund;
    }
}
