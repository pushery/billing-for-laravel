<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * A chargeback the bank took back: the money it withdrew is with the merchant again.
 *
 * Raised for a provider that reports a chargeback when it is received, before the bank has decided. `ChargebackReceived`
 * was raised for it then, and the effects registered for that event have run: access the payment bought may have ended
 * and the purchase may be reversed. This event undoes none of it by itself, because what to restore is the
 * application's policy. It names the payment, the chargeback and the amount so a listener can.
 */
final readonly class ChargebackReversed implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        /** The provider customer the payment belongs to, or empty where the payment names none. */
        public string $customerReference,
        /** The payment the chargeback was raised against, as `reference` on `ChargebackReceived`. */
        public string $reference,
        /** What the bank gave back. */
        public Money $amount,
        /** The provider's reference for the chargeback, as `disputeReference` on `ChargebackReceived`. */
        public string $disputeReference,
        /** When the provider says the chargeback was reversed, where it says so. */
        public ?CarbonImmutable $reversedAt = null,
    ) {}
}
