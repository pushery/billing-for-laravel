<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\MandateRevoked;
use Pushery\Billing\Support\BillingEventLog;

/**
 * Notifies an owner that a payment method the package could charge off-session was removed — a card
 * detached, a SEPA mandate revoked. Without it, the loss is only noticed when the next renewal fails and
 * the reactive dunning notice arrives; this lets the owner re-add a method in time, and is a security
 * signal if they did not remove it themselves.
 *
 * The effect is pure — resolve the owner, notify, audit. Once-per-delivery is the delivery machinery's job
 * (a detach fires a single event; there is no retry-storm to dedup on a domain key). An owner this app does
 * not own resolves to nobody and is skipped.
 */
final readonly class RevokeMandate
{
    public function __construct(
        private CustomerDirectory $directory,
        private MandateNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(MandateRevoked $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $this->notifier->mandateRevoked($owner, $event->mandateId);

        $this->log->record('payment_method.removed', $owner, [
            'mandate' => $event->mandateId,
        ], AuditSource::Webhook);
    }
}
