<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * A payment method the package can now charge off-session was granted — the counterpart to
 * {@see MandateRevoked}, which existed on its own for a while.
 *
 * That asymmetry was not harmless. A package that hears only the revocation can watch capability
 * disappear and never watch it arrive, so the mandate has to be written by whichever code path happened
 * to be looking — and under Mollie the path that looks is a browser redirect the customer may never
 * complete.
 *
 * The method is carried because it is the only thing that distinguishes two mandates on a screen: a
 * customer with a card and a direct debit sees two identical rows without it.
 */
final readonly class MandateEstablished implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $mandateId,
        public string $provider,
        public ?string $method = null,
        /**
         * The payment that GRANTED this mandate, where the provider establishes one that way.
         *
         * Trailing and nullable because it is not universal: a provider with a synchronous setup call
         * grants a mandate with no payment behind it, and a driver that has nothing to put here must not be
         * forced to invent something.
         *
         * It carries the weight for anything that has to recognize WHICH request a mandate answers. Keying
         * that on the customer instead looks equivalent and is not: a customer merely adding a second card
         * also establishes a mandate, and a reader keyed on the customer would take that for the answer to
         * a question somebody asked days earlier.
         */
        public ?string $paymentReference = null,
        /**
         * The card behind a card mandate, where the provider names it: its brand, the last four digits and the
         * expiry, which the expiring-card warning reads.
         *
         * Trailing and nullable for the same reason as the payment: a direct debit has no card, and a provider
         * that does not answer with one leaves it out rather than guessing.
         */
        public ?PaymentMethod $card = null,
    ) {}
}
