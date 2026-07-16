<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use DateTimeInterface;
use Pushery\Billing\Contracts\BillingEngine;

/**
 * The engine of the NullDriver: tick() is a safe no-op, so the `billing:run` scheduler can run in a
 * clone with billing disabled without erroring.
 */
final class NullBillingEngine implements BillingEngine
{
    public function tick(?DateTimeInterface $now = null): void
    {
        // Billing is disabled — there is no cycle to advance.
    }
}
