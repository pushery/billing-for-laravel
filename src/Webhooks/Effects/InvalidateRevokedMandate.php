<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\MandateNotifier;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\MandateRevoked;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Support\BillingEventLog;

/**
 * Takes a mandate out of use where the package charges it itself: it is no longer chargeable, no longer the default and
 * no longer the fallback, so the dunning draws on it no more, and the owner is told once to add another way to pay.
 *
 * For a driver whose mandates live in the package's table, the Mollie driver's, where a direct debit returned for a
 * reason that ends the mandate is what revokes it. The mandate is taken out with one conditional write, so of two
 * deliveries of the same return only the one that changed it tells the owner and writes the audit entry.
 */
final readonly class InvalidateRevokedMandate
{
    /** The status Mollie gives a mandate that can no longer be charged. */
    private const string INVALID = 'invalid';

    public function __construct(
        private string $provider,
        private CustomerDirectory $directory,
        private MandateNotifier $notifier,
        private BillingEventLog $log,
    ) {}

    public function __invoke(MandateRevoked $event): void
    {
        $changed = PaymentMandate::model()::query()
            ->where('provider', $this->provider)
            ->where('mandate_reference', $event->mandateId)
            ->where('status', PaymentMandate::CHARGEABLE)
            ->update(['status' => self::INVALID, 'is_default' => false, 'is_fallback' => false]);

        $owner = $this->directory->ownerForReference($event->customerReference);

        if ($changed === 0 || ! $owner instanceof Model) {
            return;
        }

        $this->notifier->mandateRevoked($owner, $event->mandateId);

        $this->log->record('payment_method.removed', $owner, [
            'mandate' => $event->mandateId,
        ], AuditSource::Webhook);
    }
}
