<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a money-moving operation is attempted for an owner the eligibility gate denies. This is
 * the defense-in-depth backstop on the money-moving path itself: even if a caller bypasses the UI
 * guard, the driver refuses to move money before eligibility is positively established.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class EligibilityDenied extends RuntimeException
{
    public static function forMoneyMovement(): self
    {
        return new self('The owner is not eligible to transact; money movement was refused.');
    }
}
