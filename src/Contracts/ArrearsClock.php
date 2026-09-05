<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Dunning\SuspensionPolicy;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Since when an owner is behind on what they owe a merchant — the one fact the suspension ladder needs.
 *
 * ## Why this is a seam and not a column read
 *
 * The ladder is the richest part of the dunning surface: several rungs instead of one deadline, and a
 * withdrawal per surface at a different rung each. All of that lives in {@see ConfigDunningLadder}
 * and {@see SuspensionPolicy}, neither of which touches the database — and it was
 * nonetheless out of reach for any consumer whose arrears live somewhere other than this package's
 * `billing_subscriptions`, because the one class that combines the two read that table directly.
 *
 * A consumer that holds its own view of a subscription is not an edge case: an access-entitlement timeline
 * answers a different question from a subscription row (who may see what, from when to when, surviving the
 * end of the subscription as a closed window), so adopting this package's schema does not move that view
 * into it. That is the reported case, and it is why the seam reads a STATE rather than offering a model.
 *
 * ## One fact, deliberately
 *
 * Only the clock. Not the rung: {@see ConfigDunningLadder::currentLevel()} derives
 * that from the clock and the configured schedule, so handing a stored level across the seam would be a
 * second answer to a question that already has one — and the two would drift the first time somebody
 * changed the schedule without rewriting history.
 *
 * ## Per relationship, never across
 *
 * A fan behind with creator A keeps creator B. The scope is part of the question rather than a filter
 * applied to the answer, so an implementation cannot accidentally aggregate across merchants — the mistake
 * this package made twice before the scope existed. A null merchant means the PLATFORM's own relationship,
 * which in a single-seller install is the only one there is.
 */
interface ArrearsClock
{
    /**
     * When this owner's arrears with this merchant began, or null when they are not behind at all.
     *
     * Never a provider call. The ladder runs on every gated request, and a lockout that needed the payment
     * provider to be reachable would open the gates during an outage — exactly when it should not.
     */
    public function delinquentSince(Model $owner, ?MerchantScope $merchant = null): ?DateTimeInterface;
}
