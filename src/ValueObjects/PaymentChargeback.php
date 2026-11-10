<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\ChargebackOutcome;

/**
 * One chargeback of a payment into a payment account, as its provider reported it: a Mollie chargeback or a Stripe
 * dispute.
 *
 * A reversed chargeback stays in the list it arrives in, so a listener can tell a payment whose chargeback was
 * reversed from one that was never charged back, without keeping state of its own.
 */
final readonly class PaymentChargeback
{
    /**
     * @param  string  $reference  the provider's id of the chargeback or the dispute
     * @param  Money  $amount  what the customer's bank took back with it
     * @param  ?CarbonImmutable  $bookedAt  when the provider booked it, where the provider names a time
     * @param  ?string  $reasonCode  the provider's code for the reason, such as Mollie's `AC04` or Stripe's `fraudulent`
     * @param  ?string  $reason  the provider's description of the reason, where it gives one
     * @param  ?Money  $fee  what the provider charged for handling the chargeback, where it names it: Stripe states it
     *                       on the dispute's balance transactions and gives it back with a won one, which nets to
     *                       zero. Mollie's chargeback names no fee
     */
    public function __construct(
        public string $reference,
        public Money $amount,
        public ChargebackOutcome $outcome,
        public ?CarbonImmutable $bookedAt = null,
        public ?string $reasonCode = null,
        public ?string $reason = null,
        public ?Money $fee = null,
    ) {}
}
