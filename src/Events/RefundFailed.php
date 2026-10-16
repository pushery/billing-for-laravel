<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Enums\RefundFailure;
use Pushery\Billing\ValueObjects\Money;

/**
 * A refund that will not reach the customer: it failed at the provider, or it was canceled before it was paid out.
 *
 * The customer was told the money is on its way and does not get it, and nothing refunds it again by itself. What
 * follows is the application's decision, a new refund or another way to settle the difference, so the event names the
 * payment, the refund and the amount and acts on none of them. Whatever an earlier refund event reversed or credited
 * stays as it is.
 */
final readonly class RefundFailed implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        /** The provider customer the payment belongs to, or empty where the payment names none. */
        public string $customerReference,
        /** The payment the refund was taken from. */
        public string $paymentReference,
        /** The provider's own reference for the refund. */
        public string $refundReference,
        /** What the refund was for. */
        public Money $amount,
        public RefundFailure $failure,
        /** The provider's reason, where it gives one. */
        public ?string $reason = null,
    ) {}
}
