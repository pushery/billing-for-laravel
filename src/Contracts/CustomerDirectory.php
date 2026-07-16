<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a provider customer reference (a Stripe/Mollie/Adyen customer id carried on a webhook)
 * back to the local billing owner. This is the one seam a webhook effect needs to act on the right
 * account without knowing the driver's customer vocabulary or the app's model shape; each driver
 * ships its own directory (Cashier looks the reference up on the Billable's customer column).
 */
interface CustomerDirectory
{
    /** The billing owner for a provider customer reference, or null when none is on file. */
    public function ownerForReference(string $customerReference): ?Model;
}
