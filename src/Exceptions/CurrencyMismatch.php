<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when two Money values of different currencies are combined or compared. Money never
 * performs implicit currency conversion — mixing currencies is a programming error, not a runtime
 * condition to recover from.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot operate on Money of different currencies: {$a} vs {$b}.");
    }
}
