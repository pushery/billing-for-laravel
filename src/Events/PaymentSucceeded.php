<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/** A payment settled for a customer (a charge, an invoice payment, a completed one-time checkout). */
final readonly class PaymentSucceeded implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public Money $amount,
        public string $reference,
    ) {}
}
