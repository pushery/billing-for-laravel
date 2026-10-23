<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\PaymentCancellation;

/**
 * Cancels a payment the customer has not completed yet.
 *
 * A provider reports a payment on the webhook when its status changes, not when it is created. A payment nobody
 * completes is reported only when it expires, and how long that takes depends on its method. Canceling it ends it now
 * and has it reported as canceled: a checkout the customer abandoned, or a probe payment a platform makes to see an
 * account's webhook arrive before a shop goes live.
 *
 * The Mollie rails implement it, the rails of a payment account included, and cancel in the account's mode. It is a
 * contract of its own rather than part of PaymentRails, so a PaymentRails of your own does not have to implement it:
 * ask with `instanceof` before calling it.
 */
interface CancelsPayments
{
    /**
     * Cancel the payment, or say why the provider kept it.
     *
     * A payment the provider no longer lets go of, because it is paid, already canceled, or of a method that cannot be
     * canceled any more, comes back as a cancellation that did not happen, with the provider's own words, not as an
     * exception. An error that says nothing about the payment, such as a rejected credential, a rate limit or an
     * outage, is thrown, so the call can be made again.
     */
    public function cancel(string $paymentReference): PaymentCancellation;
}
