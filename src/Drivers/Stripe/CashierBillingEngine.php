<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use DateTimeInterface;
use Pushery\Billing\Contracts\BillingEngine;

/**
 * The Stripe engine delegates the recurring cycle to Stripe Billing, so tick() is a deliberate
 * no-op — there is no local cycle to advance. The seam exists only so a local-engine driver can
 * own their cycle locally without a rewrite (see BillingEngine).
 */
final class CashierBillingEngine implements BillingEngine
{
    public function tick(?DateTimeInterface $now = null): void
    {
        // Stripe Billing drives its own recurring cycle; there is nothing to advance locally.
    }
}
