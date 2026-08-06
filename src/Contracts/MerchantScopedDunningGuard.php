<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The dunning gate asked about ONE merchant: the blocking subscription state for this owner in this
 * relationship, or null when nothing blocks there.
 *
 * ## Why this is its own interface rather than a parameter on {@see DunningGuard}
 *
 * Because appending the parameter breaks every existing implementation at load time — not at the call, at
 * the DECLARATION. That was measured rather than assumed: this package's own suite declares a `DunningGuard`
 * inline, and the changed signature fataled it before a single test ran. A consumer who bound their own
 * guard would meet the same fatal on a MINOR upgrade.
 *
 * An optional sibling interface costs a consumer nothing. One that never implements it is untouched; one
 * that wants per-merchant dunning implements it and the scoped path becomes reachable. It is the same shape
 * the money path uses to keep the marketplace unreachable until a driver opts in, and it has the property
 * that matters here: the marketplace behavior cannot half-exist.
 *
 * ## What the scope means
 *
 * A debt is owed to somebody. `blockingStateFor($owner, $merchant)` answers for that somebody only —
 * never an aggregate across the owner's rows, because an aggregate is precisely what makes arrears with
 * creator A withdraw creator B's service.
 *
 * A null scope is the PLATFORM's own subscription, not "any of them": `Subscription::forMerchant()` collapses
 * it to the platform sentinel, and in a single-seller install every row is the platform's, so the answer is
 * the one that install always got.
 */
interface MerchantScopedDunningGuard
{
    public function blockingStateFor(Model $owner, ?MerchantScope $merchant): ?SubscriptionState;
}
