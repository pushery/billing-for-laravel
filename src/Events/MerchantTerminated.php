<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * The relationship with a merchant has ended and cannot be resumed from the platform's side.
 *
 * Distinct from suspension because of what it says about money already sent: a suspended merchant can still
 * be reached for a reversal, a terminated one cannot. Anybody holding a claim against them — a chargeback
 * to recover, a negative balance to recoup — has to learn about this transition specifically, because it is
 * the moment their claim stopped being collectible.
 */
final readonly class MerchantTerminated implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        public string $accountReference,
        public string $reason,
    ) {}
}
