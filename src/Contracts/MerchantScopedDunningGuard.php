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
 * the DECLARATION. A consumer who bound their own guard would meet that fatal on a MINOR upgrade, before a
 * single request ran.
 *
 * An optional sibling interface costs a consumer nothing: one that never implements it is untouched.
 *
 * ## Who asks it
 *
 * Not the package. The route middleware asks the unscoped {@see DunningGuard}, which answers for the
 * platform's own subscription, and a merchant's content is withdrawn through the state of the subscription
 * with that merchant. This is the question for code of yours that knows the merchant, such as a merchant's
 * own pages. Ask the bound guard, and check that it implements this interface first: the shipped
 * `LocalDunningGuard` does, and a guard of your own may not.
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
