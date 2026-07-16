<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\ReceiptNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Sends the receipt when a payment goes through — the other half of the money conversation the package
 * already had one side of (the dunning notice on a failure). Like its sibling the effect is pure —
 * resolve the owner, notify, record — and the once-per-payment guarantee lives in the delivery machinery
 * (HandleWebhookEffect claims, runs and marks in one transaction), so a failed send is retried, not lost.
 *
 * It dedups on the PAYMENT reference, not the delivery: a provider that redelivers the same "payment
 * succeeded" signal mints a fresh event id, and deduping on that would mail the customer a second receipt
 * for money they only paid once.
 */
final readonly class SendPaymentReceipt implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private ReceiptNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(PaymentSucceeded $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $this->notifier->paymentSucceeded($owner, $event->amount, $event->reference);

        $this->log->record('payment.receipt_sent', $owner, [
            'reference' => $event->reference,
            'amount' => $event->amount->minorUnits,
            'currency' => $event->amount->currency,
        ], AuditSource::Webhook);
    }

    /** Once per payment — never once per provider redelivery of the same success. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof PaymentSucceeded) {
            throw new RuntimeException('SendPaymentReceipt only handles PaymentSucceeded events.');
        }

        return $event->reference;
    }
}
