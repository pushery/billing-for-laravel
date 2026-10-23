<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Enums\AccountPaymentStatus;
use Pushery\Billing\ValueObjects\Money;

/**
 * A one-off payment into a payment account changed, as its provider reported it on the account's webhook address.
 *
 * It carries where the payment stands now, never a delta: a redelivery or a later report about the same payment
 * carries the newer state, so a listener that stores the latest one is always right. Add-on, subscription and
 * marketplace events are not raised for an account's payments, so nothing of the install reads them as its own.
 */
final readonly class AccountPaymentChanged implements BillingDomainEvent
{
    /**
     * @param  string  $provider  the account's provider, such as `mollie`
     * @param  string  $account  the account's key, as the application named it
     * @param  string  $paymentReference  the provider's id of the payment, as the charge's result reported it
     * @param  Money  $amountRefunded  what went back to the customer so far, failed refunds not counted
     * @param  Money  $amountChargedBack  what the customer's bank took back and still holds
     * @param  ?string  $billingReference  the idempotency key the charge carried, such as the order's id
     * @param  array<string, string|int>  $metadata  the metadata the charge's options carried
     * @param  ?string  $refundReference  the provider's id of the refund that failed, with RefundFailed
     * @param  bool  $testmode  whether the payment ran in the provider's test mode, as the provider reports it: every
     *                          payment of a test account, and on a live account a payment made in test mode through
     *                          its OAuth access, such as a probe before the shop goes live
     */
    public function __construct(
        public string $provider,
        public string $account,
        public string $paymentReference,
        public AccountPaymentStatus $status,
        public Money $amount,
        public Money $amountRefunded,
        public Money $amountChargedBack,
        public ?string $billingReference = null,
        public array $metadata = [],
        public ?string $refundReference = null,
        public bool $testmode = false,
    ) {}
}
