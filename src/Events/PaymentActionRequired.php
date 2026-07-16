<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * A payment cannot complete until the CARDHOLDER confirms it — the bank asked for Strong Customer
 * Authentication (3-D Secure). This is a different problem from a failed payment: nothing is wrong with
 * the card, the subscription just cannot start (or renew) until the customer taps "confirm" at their bank.
 * Left unprompted the subscription silently sits `incomplete` and the customer thinks they subscribed.
 *
 * Provider-neutral: Stripe raises it as `invoice.payment_action_required`; a local engine raises it when
 * its own charge returns a "needs authentication" status.
 */
final readonly class PaymentActionRequired implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $reference,
    ) {}
}
