<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a customer needs to pay an open bank transfer: the account the money goes to, and the reference that matches
 * the transfer to the payment.
 *
 * The provider names the account and creates the reference. Show both to the customer as they are: a reference with a
 * character changed, or spaced differently, no longer matches, and the money waits at the provider unmatched.
 */
final readonly class PaymentInstructions
{
    public function __construct(
        /** The reference the customer gives with the transfer. */
        public string $reference,
        /** The IBAN of the account the money goes to. */
        public string $iban,
        /** The BIC of that account's bank, where the provider names one. */
        public ?string $bic = null,
        /** The name the transfer goes to, as the provider gives it: Mollie's `bankName`, the account holder at Stripe. */
        public ?string $recipient = null,
    ) {}
}
