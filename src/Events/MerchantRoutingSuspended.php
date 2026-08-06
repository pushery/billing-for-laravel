<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * The platform has stopped routing new money to a merchant.
 *
 * It carries the reason because the consumers act on it differently: a support tool tells the merchant what
 * to fix, a subscription policy decides what happens to buyers who are mid-period, and an operator wants to
 * know whether the provider withdrew something or somebody here made a call.
 */
final readonly class MerchantRoutingSuspended implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        public string $accountReference,
        public string $reason,
    ) {}
}
