<?php

declare(strict_types=1);

namespace Pushery\Billing\Listeners;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AffectsSeats;
use Pushery\Billing\Seats\SeatSync;

/**
 * Re-syncs a team's billed seat count whenever its membership changes.
 *
 * This is the piece that was missing: seat sync existed but nothing ever called it, so a team could add or
 * remove members all cycle and keep being billed for whatever seat count the provider happened to hold —
 * a silent under- or over-charge nobody triggered. The package does not own the join/leave events, so a
 * consumer registers its own membership events (`billing.seats.membership_events`) and this listener runs
 * on each.
 *
 * It runs AFTER COMMIT and on the queue, for two reasons that are really one: the sync makes a provider call,
 * which must not block the web request, and must not fire against a membership change that is still inside an
 * open transaction and could yet roll back — reporting seats a rollback then un-does. A transient gateway
 * failure propagates so the queue retries it; a permanent one is handled (and logged) at the seam.
 */
final readonly class SyncSeatsOnMembershipChange implements ShouldQueueAfterCommit
{
    public function __construct(
        private SeatSync $seats,
        private Repository $config,
    ) {}

    public function handle(object $event): void
    {
        $owner = $this->ownerOf($event);

        if ($owner instanceof Model) {
            $this->seats->sync($owner);
        }
    }

    /**
     * Which owner this membership event concerns. An event that names it explicitly (via {@see AffectsSeats})
     * is authoritative; otherwise the listener reads the first configured owner property that holds a model,
     * so a third-party event can still drive the sync without implementing the interface.
     */
    private function ownerOf(object $event): ?Model
    {
        if ($event instanceof AffectsSeats) {
            return $event->seatOwner();
        }

        $properties = $this->config->get('billing.seats.owner_properties', ['team', 'owner']);

        foreach (is_array($properties) ? $properties : [] as $property) {
            $name = is_string($property) ? $property : '';

            if ($name !== '' && isset($event->{$name}) && $event->{$name} instanceof Model) {
                return $event->{$name};
            }
        }

        return null;
    }
}
