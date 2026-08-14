<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * A one-time add-on was bought and paid (the neutral form of a completed one-off checkout). The
 * reference is the checkout/session identifier — the natural dedup key, so the credit is applied
 * exactly once per purchase however many times the webhook is redelivered. The paymentReference is the
 * provider PAYMENT id (a PaymentIntent), a separate key: a later refund webhook carries the payment id,
 * not the session, so this is what a reversal is matched on.
 */
final readonly class AddonPurchased implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $addonKey,
        public Money $amount,
        public string $reference,
        public ?string $paymentReference = null,
        /**
         * The declaration reference the package minted BEFORE the redirect, echoed back by the provider.
         *
         * Null on every purchase that carried none -- which is every purchase on an install with no
         * consumer-rights profile, and every one made before the declarations were collected. Null is
         * therefore an ordinary state and not a failure; what it must never do is silently read as "the
         * buyer declared nothing was needed", which is why the grant path treats a missing consent as a
         * refusal rather than a pass.
         */
        public ?string $declarationReference = null,
    ) {}
}
