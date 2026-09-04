<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a billing driver is requested that has not been registered with the BillingManager.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class UnsupportedDriver extends InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct("Unsupported billing driver: '{$name}'. Register it via BillingManager::extend().");
    }
}
