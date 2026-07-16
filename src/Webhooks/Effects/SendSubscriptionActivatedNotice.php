<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Confirms the subscription is live and names the tier. It is the counterpart to the cancellation notice,
 * and distinct from the receipt: the receipt is about the money, this is about the thing they bought being
 * switched on.
 *
 * It dedups ONCE PER SUBSCRIPTION, not per delivery and not per state change. That is the whole difficulty
 * here: a provider sends a subscription-updated event for many reasons, and an owner recovering from
 * past_due lands back on `active` — deduping on the event id would welcome them again every time they
 * recovered, which reads as a bug to the customer. A genuinely new subscription carries a new reference and
 * is genuinely a new welcome.
 */
final readonly class SendSubscriptionActivatedNotice implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private SubscriptionNotifier $notifier,
        private TierCatalog $tiers,
        private BillingEventLog $log,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        if ($event->state !== SubscriptionState::Active) {
            return; // not an activation
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $label = $event->tierKey === null ? null : $this->tiers->label($event->tierKey);

        $this->notifier->subscriptionActivated($owner, $label ?? ($event->tierKey ?? ''));

        $this->log->record('subscription.activated_notice_sent', $owner, [
            'subscription' => $event->subscriptionReference,
            'tier' => $event->tierKey,
        ], AuditSource::Webhook);
    }

    /** Once per subscription — never once per state change, or a recovery from past_due would re-welcome. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof SubscriptionStateChanged) {
            throw new RuntimeException('SendSubscriptionActivatedNotice only handles SubscriptionStateChanged events.');
        }

        return ($event->subscriptionReference ?? $event->customerReference).':activated';
    }
}
