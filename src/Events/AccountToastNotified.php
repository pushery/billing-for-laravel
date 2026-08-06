<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\ToastLevel;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;
use Pushery\Billing\Support\OwnerToast;

/**
 * Relay a transient toast to the owner over their private channel — "your subscription is active", "your
 * payment failed". The headless realtime bridge on the client turns it into a WireKit toast. A no-op unless
 * realtime is switched on.
 *
 * Fired from the webhook spine wherever the package already tells the owner something: the failed payment
 * (danger) and the subscription that just went live (success). It rides alongside the transactional mail
 * rather than replacing it — a toast reaches whoever happens to be looking at the screen, and nobody else.
 *
 * The message is a finished sentence, already in the owner's language, because the bridge that receives it
 * hands the text to a toast without a translation step. {@see OwnerToast}, which
 * is where that translation happens and why.
 */
final readonly class AccountToastNotified implements ShouldBroadcast
{
    use BroadcastsToOwner;

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
