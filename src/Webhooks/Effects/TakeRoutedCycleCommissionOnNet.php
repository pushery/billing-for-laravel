<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\TakesCycleCommissionOnNet;
use Pushery\Billing\Events\RoutedSubscriptionInvoiceDrafted;
use Pushery\Billing\Models\Subscription;

/**
 * Sets a drafted routed cycle's commission on its net, before the provider withholds it from the payment.
 *
 * The configured rate is a net rate, and a destination subscription can only carry it as a percentage of the invoice
 * total. The draft is the moment it can still be set as an amount on the net, so this asks the driver to set it there.
 *
 * ## The local row decides whether to ask at all
 *
 * A drafted invoice says nothing about routing, so this asks the subscription this package recorded. One this install
 * never mirrored, and the platform's own subscription with no merchant, stop here without a provider call, which is
 * every cycle on an install that routes nothing. What the driver finds on the provider's side, a draft that already
 * finalized or a cycle that charged no tax, it answers by leaving the invoice as it is.
 */
final readonly class TakeRoutedCycleCommissionOnNet
{
    public function __construct(private TakesCycleCommissionOnNet $commissions) {}

    public function __invoke(RoutedSubscriptionInvoiceDrafted $event): void
    {
        $subscription = Subscription::model()::query()
            ->where('provider_id', $event->subscriptionReference)
            ->first();

        if (! $subscription instanceof Subscription || ! $subscription->merchant instanceof Model) {
            return;
        }

        $this->commissions->onDraft($event->invoiceReference);
    }
}
