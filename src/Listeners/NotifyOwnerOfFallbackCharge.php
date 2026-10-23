<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Notifications\Dispatcher;
use Pushery\Billing\Events\FallbackPaymentMethodCharged;
use Pushery\Billing\Notifications\FallbackPaymentMethodChargedNotification;

/**
 * Tell an owner that a cycle was paid with their fallback because the default could not pay it.
 *
 * The event carries everything; this only delivers it, so a host that routes its notices its own way can replace
 * this listener and keep the event.
 */
final readonly class NotifyOwnerOfFallbackCharge
{
    public function __construct(private Dispatcher $notifications) {}

    public function handle(FallbackPaymentMethodCharged $event): void
    {
        // Asked of the object rather than of its traits: a host may route notifications their own way, and a check on
        // the shipped concern would refuse to tell an owner who is perfectly reachable.
        if (! method_exists($event->owner, 'notify')) {
            return;
        }

        $this->notifications->send(
            $event->owner,
            new FallbackPaymentMethodChargedNotification($event->amount, $event->method, $event->reference),
        );
    }
}
