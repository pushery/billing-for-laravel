<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * A merchant has disconnected their account from the platform.
 *
 * This is a DIFFERENT axis from losing a capability, and collapsing the two would hide the case that
 * matters most. A merchant who fails re-verification can still be reached: transfers stop, but a reversal
 * of money already sent still works. A merchant who deauthorized cannot be reached at all — transfers AND
 * reversals fail — which is precisely the state in which a clawback becomes impossible. A platform owed
 * money by that merchant has to be able to see it, so it is stored on its own flag rather than folded into
 * "cannot receive".
 */
final readonly class MerchantAccountDeauthorized implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        public string $accountReference,
    ) {}
}
