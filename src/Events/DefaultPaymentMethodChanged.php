<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * The payment method a customer is charged with by default changed, or its details did, so a local copy of it has to
 * be read again. The provider names the customer and not the method, because the method is what is read.
 */
final readonly class DefaultPaymentMethodChanged implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(public string $customerReference) {}
}
