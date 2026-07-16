<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Billing\Enums\ToastLevel;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;

/**
 * Relay a transient toast to the owner over their private channel — "your subscription is active", "your
 * payment failed". The headless realtime bridge on the client turns it into a WireKit toast. A no-op unless
 * realtime is switched on.
 *
 * Fired from the webhook spine (plan-sync success, dunning danger, …); the wiring lands with the webhook
 * milestone.
 */
final readonly class AccountToastNotified implements ShouldBroadcast
{
    use BroadcastsToOwner;
    use Dispatchable;

    public function __construct(
        public Model $owner,
        public string $message,
        public ToastLevel $level = ToastLevel::Info,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return $this->ownerChannel($this->owner);
    }

    public function broadcastAs(): string
    {
        return 'account.toast';
    }

    /** @return array{message: string, level: string} */
    public function broadcastWith(): array
    {
        return ['message' => $this->message, 'level' => $this->level->value];
    }

    public function broadcastWhen(): bool
    {
        return $this->realtimeEnabled();
    }
}
