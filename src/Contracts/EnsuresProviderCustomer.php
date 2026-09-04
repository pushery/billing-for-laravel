<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\CustomerBelongsToAnotherProvider;

/**
 * Get the provider's customer id for a billable, creating one if this is their first time.
 *
 * ## Why this had to exist before a subscription could be started
 *
 * A provider that establishes a mandate through a first payment needs a customer to attach that payment
 * to — the mandate belongs to the customer, not to the payment. Reading the reference off an existing
 * mandate works for everybody who already has one and answers null for exactly the person a subscribe
 * flow is for: somebody with no payment method yet.
 *
 * ## Separate from `CustomerDirectory`, which goes the other way
 *
 * That one resolves a reference arriving on a webhook back to an owner. This one turns an owner into a
 * reference, and it may WRITE. Reading and creating are different privileges, and a package that can be
 * asked "who is this?" should not thereby be able to create accounts at a payment provider.
 */
interface EnsuresProviderCustomer
{
    /**
     * The provider customer id for this billable, created on first use and persisted.
     *
     * @throws CustomerBelongsToAnotherProvider when the billable already
     *                                          carries a reference this
     *                                          provider did not issue
     */
    public function customerFor(Model $billable): string;
}
