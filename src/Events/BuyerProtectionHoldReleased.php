<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\ValueObjects\Money;

/**
 * The protection period ended in the seller's favor and the money is on its way to them.
 *
 * Announced for EVERY route to that outcome — the buyer confirming, the auto-release at the end of the
 * confirmation window, an operator resolving in the seller's favor — because a consuming application cares
 * that it happened, not which of the three doors it came through.
 */
final readonly class BuyerProtectionHoldReleased implements BillingDomainEvent
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
