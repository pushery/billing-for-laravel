<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AdoptsCollectedPaymentMethod;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\PaymentMethodCollected;
use Pushery\Billing\Support\BillingEventLog;

/**
 * Makes the payment method a customer added on the hosted page the one the next charge reads.
 *
 * The recovery screen sends a past-due owner to that page to replace the card that failed, and the banner
 * above it promises that doing so keeps the subscription active. Adding a method keeps no such promise on
 * its own: the provider attaches it to the customer and changes nothing about which method it charges. So
 * without this effect the provider's next retry takes the old card and fails again, however many new cards
 * the owner adds.
 *
 * It runs on the webhook rather than on the return redirect because the customer may never come back to
 * the app after the page, and the notification arrives either way. A second delivery is harmless: the same
 * method is made the default a second time.
 *
 * An owner this app does not know resolves to nobody and nothing is changed, the same rule every effect
 * that acts on a customer follows.
 */
final readonly class AdoptCollectedPaymentMethod
{
    public function __construct(
        private CustomerDirectory $directory,
        private AdoptsCollectedPaymentMethod $methods,
        private BillingEventLog $log,
    ) {}

    public function __invoke(PaymentMethodCollected $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        $this->methods->adopt($event->customerReference, $event->collectionReference);

        $this->log->record('payment_method.default_changed', $owner, [
            'collection' => $event->collectionReference,
        ], AuditSource::Webhook);
    }
}
