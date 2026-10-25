<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\BalanceCycleTerms;

/**
 * The terms the next period of a subscription paid from an internal balance is sold under.
 *
 * A renewal runs on the package's schedule, and nobody hands it the commission or the buyer's tax. A host that binds
 * `InternalBalance` and sells subscriptions from it binds this as well and answers for each renewal, the way it
 * answers for a sale it starts itself: the package keeps no opinion of its own about where a buyer is taxed or what a
 * merchant's commission is.
 *
 * Without a binding no subscription paid from a balance is renewed. A period that cannot be sold is not collected,
 * and the subscription is handled as one the balance did not cover.
 */
interface BalanceSubscriptionTerms
{
    /**
     * The terms of the subscription's next period, or null where it is not to be renewed.
     *
     * Null declines the renewal, for a buyer the host no longer sells to, and the subscription is then handled as
     * one the balance did not cover.
     */
    public function termsFor(Subscription $subscription, Model $merchant, Model $buyerOwner): ?BalanceCycleTerms;
}
