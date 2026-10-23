<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundState;

/**
 * Reads back the refunds of a payment, and what is left of it to refund.
 *
 * The idempotency key of {@see PaymentRails::refund()} protects a retry only for as long as the provider remembers
 * the key: an hour at Mollie, a day at Stripe. When the answer to a refund is lost, after the request went out and
 * before the response came back, a retry after that window creates a second refund, and the customer is paid
 * twice. So before a retry, read the payment's refunds and look for the key: `refund()` stores the key with each
 * refund it makes, and {@see RefundState::$key} carries it back.
 *
 * The Mollie and Stripe rails implement it, the rails of a payment account included. It is a contract of its own
 * rather than part of PaymentRails, so a PaymentRails of your own does not have to implement it: ask with
 * `instanceof` before calling it.
 */
interface ReadsRefunds
{
    /**
     * Every refund the provider holds for the payment, whatever its status, in the order the provider lists them.
     *
     * @return list<RefundState>
     */
    public function refundsOf(string $chargeReference): array;

    /**
     * What can still be refunded of the payment: Mollie's own `amountRemaining`, and at Stripe what was captured
     * less what was refunded. Null where there is no such amount, for a payment that was never paid, or one Mollie
     * does not allow refunds on.
     */
    public function refundableOf(string $chargeReference): ?Money;
}
