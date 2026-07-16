<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\TrialNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\TrialEnding;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Sends the trial-ending reminder. Like the dunning notice, the effect is pure — resolve the owner, notify,
 * record — and the once-per-trial-end guarantee lives in the delivery machinery (HandleWebhookEffect claims,
 * runs and marks in one transaction), so a failed send is retried rather than lost.
 *
 * It dedups on the SUBSCRIPTION and its trial-end moment, not the delivery: the provider mints a fresh event
 * id for every redelivery of the same "trial will end" signal, so deduping on the event id would mail the
 * customer once per redelivery. A trial that is later extended carries a new end date and is a new reminder.
 */
final readonly class SendTrialEndingNotice implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private TrialNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(TrialEnding $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $this->notifier->trialEnding($owner, $event->trialEndsAt);

        $this->log->record('trial.ending_notice_sent', $owner, [
            'subscription' => $event->subscriptionReference,
            'trial_ends_at' => $event->trialEndsAt->format('Y-m-d'),
        ], AuditSource::Webhook);
    }

    /** Once per subscription's trial end — never once per provider redelivery of the signal. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof TrialEnding) {
            throw new RuntimeException('SendTrialEndingNotice only handles TrialEnding events.');
        }

        return $event->subscriptionReference.':'.$event->trialEndsAt->getTimestamp();
    }
}
