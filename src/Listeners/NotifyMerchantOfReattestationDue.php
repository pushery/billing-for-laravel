<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Notifications\Dispatcher;
use Pushery\Billing\Events\CreatorReattestationDue;
use Pushery\Billing\Notifications\ReattestationDueNotification;

/**
 * Tell a creator that their tax declaration is due, with the date their hold begins.
 *
 * The event carries everything; this only delivers it, so a host that routes its notices its own way can
 * replace this listener and keep the event.
 */
final readonly class NotifyMerchantOfReattestationDue
{
    public function __construct(private Dispatcher $notifications) {}

    public function handle(CreatorReattestationDue $event): void
    {
        // Asked of the object rather than of its traits: a host may route notifications their own way, and
        // a check on the shipped concern would refuse to tell a merchant who is perfectly reachable.
        if (! method_exists($event->merchant, 'notify')) {
            return;
        }

        $this->notifications->send(
            $event->merchant,
            new ReattestationDueNotification($event->holdFrom, $event->lastReminder),
        );
    }
}
