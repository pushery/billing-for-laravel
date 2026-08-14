<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\ValueObjects\Money;

/**
 * Nobody decided in time, and this package will not decide for them.
 *
 * The buyer never confirmed and never disputed, and the decision deadline passed. Releasing on silence would
 * pay a seller over a buyer's unanswered complaint; refunding on silence would take money from a seller who
 * did nothing wrong. Both are somebody's loss, so the platform is told instead — which only works if it is
 * told, and until now the only channel was the console output of a cron run.
 */
final readonly class BuyerProtectionResolutionRequired implements BillingDomainEvent
{
    public function __construct(
        /** The provider's reference for the sale the hold sits over. */
        public string $chargeReference,
        /**
         * The merchant's morph type and key, as the hold recorded them, or null on an unrouted sale.
         *
         * The key is int-or-string because a consumer's model decides that, and narrowing it here would make
         * this event unusable for every installation with a uuid key.
         */
        public ?string $merchantType,
        public int|string|null $merchantId,
        /** What the outcome is worth to whoever receives it. */
        public Money $amount,
        /** The state the hold now stands in, so a listener need not re-read the row to know. */
        public BuyerProtectionState $state,
    ) {}
}
