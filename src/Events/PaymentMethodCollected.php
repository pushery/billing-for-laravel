<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * A customer finished the package's hosted page for adding a payment method.
 *
 * The page promises that the method it collects is the one used from now on: the recovery screen sends a
 * past-due owner there to replace the card that failed. Collecting a method does not make it the one a
 * provider charges, though. The provider attaches it to the customer and sets nothing, so the handler of
 * this event is what turns "a method was added" into "the next charge reads it".
 *
 * The collection reference is the provider's handle for the collection itself (a setup intent at Stripe),
 * not the method: the notification that the page was completed does not carry the method's id, and the
 * handler reads it from the provider rather than trusting a value the payload does not hold.
 */
final readonly class PaymentMethodCollected implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $collectionReference,
        public string $provider,
    ) {}
}
