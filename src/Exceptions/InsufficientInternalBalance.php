<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Contracts\InternalBalance;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * The buyer's internal balance does not cover a sale paid from it.
 *
 * Thrown by the host's {@see InternalBalance} before anything is taken, and passed on
 * unchanged: nothing is recorded for the sale, and the amount it would have needed is on the exception for a
 * screen that offers another way to pay.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class InsufficientInternalBalance extends RuntimeException
{
    private function __construct(string $message, public readonly Money $amount)
    {
        parent::__construct($message);
    }

    public static function toCover(Money $amount): self
    {
        return new self(
            "The internal balance does not cover {$amount->minorUnits} {$amount->currency}; nothing was taken.",
            $amount,
        );
    }
}
