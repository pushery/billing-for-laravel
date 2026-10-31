<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Contracts\CancelsPayments;

/**
 * The outcome of {@see CancelsPayments::cancel()}: whether the payment is canceled now, and where it is not, why the
 * provider kept it.
 */
final readonly class PaymentCancellation
{
    public function __construct(
        public string $paymentReference,
        public bool $canceled,
        /**
         * Why the provider kept the payment, in its own terms: Stripe's error code, the HTTP status where the provider
         * names none, as Mollie never does, or the status the payment still has. Null on a payment that was canceled.
         */
        public ?string $failureCode = null,
        /** The provider's own words for keeping it, where it gave any. */
        public ?string $failureMessage = null,
    ) {}
}
