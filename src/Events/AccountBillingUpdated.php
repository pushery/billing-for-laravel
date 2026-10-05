<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;

/**
 * Broadcast on the owner's private channel when their billing state changes, so the account-hub screens
 * (overview, subscription, recovery) live-refresh instead of waiting for a reload. It carries no payload —
 * the client re-fetches — so there is nothing sensitive on the wire, and the queued broadcast holds the owner
 * as its key rather than its attributes. A no-op unless realtime is switched on.
 *
 * Fired by the plan sync when a provider event actually MOVES something — a state, a tier, a period. A
 * redelivery of an event already applied, or one arriving out of order, changes nothing and broadcasts
 * nothing, so a provider retrying a webhook does not make every open screen re-fetch.
 *
 * Raised inside a transaction, it leaves when that transaction commits, the outermost one included: a screen
 * re-fetches state that has been written, and a run that rolls back raises nothing.
 */
final readonly class AccountBillingUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToOwner;
    use SerializesModels;

    public function __construct(public Model $owner) {}

    public function broadcastOn(): PrivateChannel
    {
        return $this->ownerChannel($this->owner);
    }

    public function broadcastAs(): string
    {
        return 'billing.updated';
    }

    /**
     * Nothing: the screens re-fetch what they show. Without a payload of its own, a broadcast sends every
     * public property, and the owner model is one.
     *
     * @return array{}
     */
    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastWhen(): bool
    {
        return $this->realtimeEnabled();
    }
}
