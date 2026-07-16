<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * A payment attempt failed for a customer (the neutral form of a failed invoice/charge). The
 * reference is the failing invoice identifier — the dedup key the dunning-notice effect commits
 * before sending, so a redelivered failure never re-sends the notice.
 */
final readonly class PaymentFailed implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public Money $amount,
        public string $reference,
    ) {}
}
