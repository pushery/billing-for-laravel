<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\PaymentActionNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\PaymentActionRequired;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Nudges the customer to confirm a payment their bank held for authentication (3-D Secure). Like its
 * siblings the effect is pure — resolve the owner, notify, record — with the once-per-invoice guarantee
 * in the delivery machinery.
 *
 * It dedups on the INVOICE the authentication is holding, not the delivery: the provider mints a fresh
 * event id each time it re-signals "still needs action", and deduping on that would nag the customer on
 * every redelivery. A new invoice that needs authentication is a genuinely new prompt.
 */
final readonly class SendPaymentActionRequiredNotice implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private PaymentActionNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(PaymentActionRequired $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $this->notifier->paymentActionRequired($owner, $event->reference);

        $this->log->record('payment.action_required_notice_sent', $owner, [
            'reference' => $event->reference,
        ], AuditSource::Webhook);
    }

    /** Once per invoice awaiting authentication — never once per provider redelivery of the same hold. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof PaymentActionRequired) {
            throw new RuntimeException('SendPaymentActionRequiredNotice only handles PaymentActionRequired events.');
        }

        return $event->reference.':action_required';
    }
}
