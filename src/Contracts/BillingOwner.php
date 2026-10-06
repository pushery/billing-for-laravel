<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Implemented by a model that owns billing (a user or a team). Exposes the provider customer
 * reference so drivers can act without knowing the app's model shape.
 *
 * @deprecated Nothing in the package reads it. The drivers and the customer directories read the provider
 *             customer reference from the column `billing.customer.column` names, because a webhook finds its
 *             owner by querying that column, and a method cannot be queried. Keep the reference in that
 *             column; implementing this interface changes nothing.
 */
interface BillingOwner
{
    /** The provider customer reference for this owner, or null before one exists. */
    public function billingCustomerReference(): ?string;
}
