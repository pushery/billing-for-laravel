<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/** A stored mandate/payment method was revoked, so it can no longer be charged off-session. */
final readonly class MandateRevoked implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $mandateId,
    ) {}
}
