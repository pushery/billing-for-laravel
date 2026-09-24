<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\SubscriptionNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * Tells the owner their subscription was canceled and — the part that actually matters — WHEN their access
 * ends. It fires on the GRACE state only: canceled but paid through the moment it ends, which is when the
 * customer needs the date. A subscription that has already lapsed (Ended) is past the point of a useful
 * "your access ends on…", and every other state is not a cancellation at all.
 *
 * The date is the one the cancellation named, or the period end when it named none. Without either there is
 * no date to give, so nothing is sent rather than a notice whose whole point is missing. Like its siblings the effect is pure — resolve the owner, notify, record — with the
 * once-per-cancellation guarantee in the delivery machinery (claim, run and mark in one transaction).
 *
 * It dedups on the SUBSCRIPTION and its access-end moment, not the delivery: a provider redelivering the
 * same cancellation mints a fresh event id, and deduping on that would mail the customer twice. A
 * cancellation later re-scheduled to a different end date is a genuinely different notice.
 */
final readonly class SendSubscriptionCanceledNotice implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private SubscriptionNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        $endsAt = $this->endsAt($event);

        if ($event->state !== SubscriptionState::Grace || $endsAt === null) {
            return; // not a cancellation with a date to announce
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $accessEndsAt = Carbon::createFromTimestamp($endsAt);

        $this->notifier->subscriptionCanceled($owner, $accessEndsAt);

        $this->log->record('subscription.canceled_notice_sent', $owner, [
            'subscription' => $event->subscriptionReference,
            'access_ends_at' => $accessEndsAt->toDateString(),
        ], AuditSource::Webhook);
    }

    /** Once per subscription's cancellation (keyed on the access-end moment) — never once per redelivery. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof SubscriptionStateChanged) {
            throw new RuntimeException('SendSubscriptionCanceledNotice only handles SubscriptionStateChanged events.');
        }

        return ($event->subscriptionReference ?? $event->customerReference).':canceled:'.($this->endsAt($event) ?? 0);
    }

    /**
     * The moment access ends: the date the cancellation named, or the period end when it named none.
     *
     * The named date first, because a cancellation to a date inside the period ends there, and announcing the
     * period end would promise the owner weeks they no longer have.
     */
    private function endsAt(SubscriptionStateChanged $event): ?int
    {
        return $event->endsAt ?? $event->periodEnd;
    }
}
