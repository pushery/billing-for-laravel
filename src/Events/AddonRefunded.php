<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\ValueObjects\Money;

/**
 * A charge was refunded (the neutral form of the provider's refund webhook). The paymentReference is
 * the provider PAYMENT id that a one-time add-on recorded when it was bought, so a reversal is matched
 * to the purchase it undoes. The amount is the provider's CUMULATIVE refunded total, not the delta —
 * the add-on ledger claws back only the part it has not already reversed, so two partial refunds and a
 * redelivery each do the right thing.
 *
 * A refund of anything that is not a tracked add-on (a subscription invoice, an ad-hoc charge) simply
 * matches no purchase and reverses nothing.
 */
final readonly class AddonRefunded implements BillingDomainEvent
{
    public function __construct(
        public string $paymentReference,
        public Money $cumulativeRefunded,
        public ?string $reason = null,
    ) {}
}
