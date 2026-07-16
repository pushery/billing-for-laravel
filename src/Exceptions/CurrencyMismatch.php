<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when two Money values of different currencies are combined or compared. Money never
 * performs implicit currency conversion — mixing currencies is a programming error, not a runtime
 * condition to recover from.
 */
final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot operate on Money of different currencies: {$a} vs {$b}.");
    }
}
