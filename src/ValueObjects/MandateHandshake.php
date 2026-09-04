<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The start of a mandate that only the customer can finish.
 *
 * Deliberately NOT a MandateReference. There is no mandate yet — there is a payment in progress and a
 * place to send somebody, and calling it a reference would let a caller store it as one. What arrives
 * later, over the webhook, is the mandate; this is the receipt for having asked.
 *
 * `paymentReference` is what ties the two together: the webhook names the payment, and that is how the
 * eventual mandate is matched back to the customer who started this.
 */
final readonly class MandateHandshake
{
    public function __construct(
        /** Where the customer has to go to establish the mandate. */
        public string $checkoutUrl,
        /** The first payment's reference, which the webhook will name when it reports the outcome. */
        public string $paymentReference,
    ) {}
}
