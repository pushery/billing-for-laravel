<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\DunningNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Sends the dunning notice on a failed payment. The effect itself is pure — resolve the owner, notify.
 * The once-per-invoice guarantee lives in the delivery machinery (HandleWebhookEffect claims, runs and
 * marks in one transaction), which is what lets a failed send be retried instead of being lost: the
 * package used to record "notified" BEFORE sending, so an SMTP hiccup meant the notice was never sent
 * and never would be.
 *
 * It dedups on the INVOICE, not the delivery: a provider mints a fresh event id for every retry of the
 * same failing invoice, so deduping on the event id would mail the customer once per retry.
 */
final readonly class SendDunningNotice implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private DunningNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(PaymentFailed $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $this->notifier->paymentFailed($owner, $event->amount, $event->reference);

        $this->log->record('dunning.notice_sent', $owner, [
            'invoice' => $event->reference,
            'amount' => $event->amount->minorUnits,
            'currency' => $event->amount->currency,
        ], AuditSource::Webhook);
    }

    /** Once per failing INVOICE — never once per provider retry of it. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof PaymentFailed) {
            throw new RuntimeException('SendDunningNotice only handles PaymentFailed events.');
        }

        return $event->reference;
    }
}
