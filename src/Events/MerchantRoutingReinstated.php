<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * A suspended merchant may receive money again.
 *
 * Emitted only on a real transition. A provider that reports healthy capabilities every few minutes would
 * otherwise produce a stream of "reinstated" events for a merchant who was never suspended, and anything
 * downstream that notifies or unblocks on it would fire on each one.
 */
final readonly class MerchantRoutingReinstated implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        public string $accountReference,
    ) {}
}
