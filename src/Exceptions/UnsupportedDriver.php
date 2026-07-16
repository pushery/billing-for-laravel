<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a billing driver is requested that has not been registered with the BillingManager.
 */
final class UnsupportedDriver extends InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct("Unsupported billing driver: '{$name}'. Register it via BillingManager::extend().");
    }
}
