<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Notifications\Dispatcher;
use Pushery\Billing\Events\SellerDataReminderDue;
use Pushery\Billing\Notifications\SellerDataReminderNotification;

/**
 * Ask a seller for the details their record is missing.
 *
 * The event carries everything; this only delivers it, so a host that routes its notices its own way can
 * replace this listener and keep the event.
 */
final readonly class NotifyMerchantOfSellerDataReminder
{
    public function __construct(private Dispatcher $notifications, private Repository $config) {}

    public function handle(SellerDataReminderDue $event): void
    {
        if (! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return;
        }

        // Asked of the object rather than of its traits: a host may route notifications their own way, and
        // a check on the shipped concern would refuse to tell a seller who is perfectly reachable.
        if (! method_exists($event->merchant, 'notify')) {
            return;
        }

        $this->notifications->send($event->merchant, new SellerDataReminderNotification(
            $event->episodeId,
            $event->reminder,
            $event->missingFields,
            $event->measureFrom,
            $event->measure,
        ));
    }
}
