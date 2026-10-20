<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/** The provider deleted the customer, so its reference names nothing any more and no call can be made with it. */
final readonly class CustomerDeleted implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(public string $customerReference) {}
}
