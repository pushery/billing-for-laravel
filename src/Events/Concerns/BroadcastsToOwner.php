<?php

declare(strict_types=1);

namespace Pushery\Billing\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Shared plumbing for the account-hub broadcast events: an owner-scoped private channel and the opt-in gate.
 * The channel follows the RESOLVED billing owner (user XOR team), so one owner never receives another's
 * stream; broadcasting is off unless it is explicitly switched on.
 */
trait BroadcastsToOwner
{
    /** The owner's private channel, scoped by morph class + key. */
    protected function ownerChannel(Model $owner): PrivateChannel
    {
        return new PrivateChannel($this->ownerChannelName($owner));
    }

    /** The channel name (without the `private-` prefix), for a listener that needs the raw string. */
    protected function ownerChannelName(Model $owner): string
    {
        $key = $owner->getKey();
        $id = is_int($key) || is_string($key) ? (string) $key : '';

        return 'billing.'.$owner->getMorphClass().'.'.$id;
    }

    /**
     * Broadcasting is opt-in: a no-op unless realtime is switched on. A native app or an install without a
     * broadcaster (no Reverb key) leaves it off and falls back to the bounded poll instead of broadcasting.
     */
    protected function realtimeEnabled(): bool
    {
        return (bool) Config::get('billing.realtime.enabled', false);
    }
}
