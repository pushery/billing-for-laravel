<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\AccountPaymentStatus;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentChargeback;

/**
 * A one-off payment into a payment account changed, as its provider reported it on the account's webhook address.
 *
 * It carries where the payment stood when the package read it, never a delta, and `observedAt` names that moment. A
 * queue can run a report after a newer one about the same payment: a listener that failed and runs again later, or a
 * replay. A listener that stores the latest state keeps the report with the later `observedAt` and drops the other.
 * Add-on, subscription and marketplace events are not raised for an account's payments, so nothing of the install
 * reads them as its own.
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
     * @param  list<PaymentChargeback>  $chargebacks  every chargeback of the payment with its time, reason and outcome,
     *                                                a reversed one included; `amountChargedBack` keeps counting only
     *                                                what the bank still holds
     * @param  CarbonImmutable  $observedAt  when the package worked out where the payment stands, so of two reports
     *                                       about the same payment the later one is the newer state
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
        public array $chargebacks = [],
        public CarbonImmutable $observedAt = new CarbonImmutable,
    ) {}
}
