<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;

/**
 * Broadcast on the owner's private channel when their billing state changes, so the account-hub screens
 * (overview, subscription, recovery) live-refresh instead of waiting for a reload. It carries no payload —
 * the client re-fetches — so there is nothing sensitive on the wire. A no-op unless realtime is switched on.
 *
 * Fired from the webhook spine (plan sync, dunning, …); the wiring lands with the webhook milestone.
 */
final readonly class AccountBillingUpdated implements ShouldBroadcast
{
    use BroadcastsToOwner;
    use Dispatchable;

    public function __construct(public Model $owner) {}

    public function broadcastOn(): PrivateChannel
    {
        return $this->ownerChannel($this->owner);
    }

    public function broadcastAs(): string
    {
        return 'billing.updated';
    }

    public function broadcastWhen(): bool
    {
        return $this->realtimeEnabled();
    }
}
