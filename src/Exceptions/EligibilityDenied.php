<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a money-moving operation is attempted for an owner the eligibility gate denies. This is
 * the defence-in-depth backstop on the money-moving path itself: even if a caller bypasses the UI
 * guard, the driver refuses to move money before eligibility is positively established.
 */
final class EligibilityDenied extends RuntimeException
{
    public static function forMoneyMovement(): self
    {
        return new self('The owner is not eligible to transact; money movement was refused.');
    }
}
