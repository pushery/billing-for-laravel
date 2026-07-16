<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Implemented by a model that owns billing (a user or a team). Exposes the provider customer
 * reference so drivers can act without knowing the app's model shape.
 */
interface BillingOwner
{
    /** The provider customer reference for this owner, or null before one exists. */
    public function billingCustomerReference(): ?string;
}
