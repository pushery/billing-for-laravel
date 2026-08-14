<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\ValueObjects\Money;

/**
 * The protection period ended in the buyer's favor and the money goes back to them.
 *
 * The counterpart of a release and deliberately its own event rather than a flag on one: an application
 * notifying a seller that they have been paid and one notifying a buyer that they have been refunded are
 * different messages to different people, and a single event with a boolean invites sending the wrong one.
 */
final readonly class BuyerProtectionHoldRefunded implements BillingDomainEvent
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
