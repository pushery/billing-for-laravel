<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/** A chargeback/dispute was opened against a settled payment. */
final readonly class ChargebackReceived implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $reference,
        public Money $amount,
    ) {}
}
