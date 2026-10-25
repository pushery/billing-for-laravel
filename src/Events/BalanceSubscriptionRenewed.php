<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * The next period of a subscription paid from an internal balance was taken from the buyer's balance.
 *
 * Dispatched after the period was sold: the charge row, the merchant's share and the buyer's document exist, and the
 * subscription carries the new period. The first period is not announced here, because the call that started the
 * subscription returns it.
 */
final readonly class BalanceSubscriptionRenewed implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        /** What the period cost the buyer. */
        public Money $amount,
        /** The reference of the charge the period was sold under. */
        public string $chargeReference,
    ) {}
}
