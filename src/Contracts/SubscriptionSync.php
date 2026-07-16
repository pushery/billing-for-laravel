<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Events\SubscriptionStateChanged;

/**
 * Pull the billable's current provider subscription as the SAME neutral event the webhook produces, or
 * null when it has none. This is the return-reconcile seam: after a hosted checkout the customer is
 * redirected back before the webhook may have arrived, so the app reads the provider directly and applies
 * the identical rules — the "I paid but it says Free" gap closes.
 *
 * Ordering caveat: the event's occurredAt is the subscription's own creation stamp, not a fresh event
 * clock. That is deliberate — it lets a reconcile win the first write (there is no local row yet) while
 * still losing to a genuinely newer webhook state, under the plan-sync effect's out-of-order guard.
 */
interface SubscriptionSync
{
    public function pull(Model $billable): ?SubscriptionStateChanged;
}
