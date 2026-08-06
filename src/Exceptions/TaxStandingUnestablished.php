<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A sale was about to be made on behalf of a merchant whose taxation nobody has established.
 *
 * Thrown BEFORE any provider call, like its receiving-side sibling, and for the same reason: once a routed
 * charge is in flight there is no clean way back, and the document it produces is the problem.
 *
 * There is no safe guess here, and that is a symmetry rather than caution. Assume the merchant charges tax
 * normally and the settlement document states tax a small business does not owe — at which point the
 * recipient owes it merely because a document says so, unless they object in time to a document they never
 * asked for. Assume the opposite and the document understates a real liability and forfeits a deduction.
 * The two errors point in opposite directions, so neither default is the conservative one and holding is.
 *
 * It is deliberately distinct from {@see ReceiveEligibilityDenied}. That one says the merchant cannot be
 * paid; this one says nobody knows how their supply is taxed. Different causes, different fixes, different
 * people — and one exception for both would send whoever reads it to the wrong one.
 *
 * **What to do about it is not "retry".** Somebody records the merchant's standing — usually the merchant
 * themselves, through the platform's own declaration flow — and the sale goes through. A declaration that
 * simply expired reads the same way here, which is intended: a statement about a year that has ended is not
 * a weaker answer, it is no answer.
 */
final class TaxStandingUnestablished extends RuntimeException
{
    public static function forMerchant(): self
    {
        return new self(
            'Nobody has established how this merchant is taxed, so the routed sale was refused before it '
            .'reached the provider. Record the merchant\'s tax standing — or renew a declaration that has '
            .'expired — and the sale can proceed. There is no default standing to fall back on: guessing '
            .'high and guessing low produce errors in opposite directions, so neither is the safe one.'
        );
    }
}
