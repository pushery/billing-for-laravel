<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;

/**
 * The upper of the two billing layers: the recurring cycle, proration, invoices, coupons, trials,
 * dunning and tax. Two shapes of driver implement it:
 *
 *  - the Stripe driver DELEGATES to Stripe Billing and no-ops {@see tick()} (Stripe drives its own
 *    cycle);
 *  - the Mollie/Adyen drivers OWN the cycle as a package-local engine, and {@see tick()} advances
 *    every due subscription (invoked by the `billing:run` scheduler command).
 *
 * The seam exists in v1 even though Stripe does not need it, so Mollie/Adyen slot in without a
 * rewrite.
 */
interface BillingEngine
{
    /** Advance every subscription whose cycle is due at the given moment (default: now). */
    public function tick(?DateTimeInterface $now = null): void;
}
