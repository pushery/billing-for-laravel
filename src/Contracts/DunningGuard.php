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
 *
 * ## This signature is deliberately NOT merchant-scoped
 *
 * Arrears are per merchant — a fan behind with one creator keeps the others. Asking that question needs a
 * merchant, and this method has nowhere to put one.
 *
 * Appending an optional parameter here was tried and rejected on evidence: every existing implementation,
 * including the ones this package's own suite declares inline, fatals at load time on the changed
 * declaration. That is a MAJOR break, and the marketplace work ships as MINOR.
 *
 * So the scoped question lives on a SEPARATE, optional interface — {@see MerchantScopedDunningGuard} — which
 * an implementation may add without any consumer noticing. A caller that needs the scope checks for it;
 * one that does not keeps asking exactly what it always asked.
 */
interface DunningGuard
{
    public function blockingState(Model $owner): ?SubscriptionState;
}
