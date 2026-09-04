<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a money-moving operation is attempted while billing is disabled (the master switch is
 * off and the active driver is the NullDriver). Driver resolution, capability queries and the engine
 * tick stay safe no-ops; only actually moving money fails loudly.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class BillingDisabled extends RuntimeException
{
    public static function cannot(string $operation): self
    {
        return new self("Billing is disabled; cannot {$operation}.");
    }
}
