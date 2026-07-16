<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SubscriptionState;

/**
 * The hot-path dunning gate: the blocking subscription state for an owner, or null when nothing
 * blocks. Reads ONLY the local subscription row (via the presenter) — no provider call — so it is
 * outage-safe and cheap. It exists because a synced past_due/incomplete subscription pulls the tier
 * to zero, which would otherwise silently grant the free allowance; it is decoupled from whatever
 * feature it gates.
 */
interface DunningGuard
{
    public function blockingState(Model $owner): ?SubscriptionState;
}
