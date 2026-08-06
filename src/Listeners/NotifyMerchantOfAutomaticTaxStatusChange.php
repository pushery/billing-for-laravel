<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Notifications\Dispatcher;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Events\CreatorTaxStatusChanged;
use Pushery\Billing\Notifications\TaxStatusChangedNotification;

/**
 * Tells a merchant when the platform changed their tax standing without being asked.
 *
 * Only the automatic case. A merchant who declared the change themselves, or had it corrected by somebody
 * they spoke to, already knows — telling them again is noise, and noise is what makes the notice that
 * matters get ignored.
 *
 * The automatic one is different in kind: the platform read its own records, concluded the merchant crossed
 * a limit, and acted from a date the merchant never saw. Without this they keep filing as they were and hear
 * about it from an authority instead.
 *
 * Silent where the merchant cannot receive notifications at all — that is a host's arrangement to make, and
 * a package that threw here would break a status change over a delivery detail.
 */
final readonly class NotifyMerchantOfAutomaticTaxStatusChange
{
    public function __construct(private Dispatcher $notifications) {}

    public function handle(CreatorTaxStatusChanged $event): void
    {
        if ($event->source !== CreatorTaxStatusSource::AutoFlip) {
            return;
        }

        // Asked of the object rather than of its traits: a host may route notifications their own way, and
        // a check on the shipped trait would refuse to tell a merchant who is perfectly reachable.
        if (! method_exists($event->merchant, 'notify')) {
            return;
        }

        $this->notifications->send(
            $event->merchant,
            new TaxStatusChangedNotification($event->previous, $event->current, $event->effectiveFrom),
        );
    }
}
