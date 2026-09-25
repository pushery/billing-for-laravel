<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * A sale at the counter was taken off the reader before it was paid, and the provider closed its payment.
 *
 * A declined card does not raise this: the payment stays open and the buyer can present another card. Only a
 * payment the provider has closed for good ends the sale, and it ends without a receipt, because nothing was sold.
 */
final readonly class InPersonSaleCanceled implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        /** The provider's reference for the payment, as the sale's row records it. */
        public string $paymentReference,
    ) {}
}
