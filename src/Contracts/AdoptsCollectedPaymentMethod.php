<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Makes a payment method the customer just added on the hosted page the one the provider charges next.
 *
 * A driver whose hosted page leaves a new method merely attached implements this, and only such a driver
 * binds it. At Stripe a setup-mode Checkout Session attaches the method and sets nothing, and a subscription
 * that carries its own default method ignores the customer's default altogether, so both have to move.
 * A driver that has no hosted page for adding a method (Mollie) never raises the event and binds nothing.
 */
interface AdoptsCollectedPaymentMethod
{
    /**
     * @param  string  $customerReference  the provider customer the collection was made for
     * @param  string  $collectionReference  the provider's handle for the collection, as the event names it
     */
    public function adopt(string $customerReference, string $collectionReference): void;
}
