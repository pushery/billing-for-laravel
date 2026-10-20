<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The canonical state of an owner's billing, read the way the account hub reads it.
 *
 * Screens of an application's own resolve this class from the container and ask it, rather than putting its steps
 * together themselves: the owner's newest subscription of the default type, with the platform or with the merchant
 * given; that subscription's snapshot, or the owner's own when there is none; and the presenter that collapses either
 * to one state. Every account-hub screen asks this same object, so a change to how the hub reads a state reaches
 * every screen that asks here.
 *
 * `SubscriptionStateReader` answers a different question, whether a subscription grants access, and has no answer
 * for an owner without one. This class always has one: an owner without a subscription reads as on a generic trial or
 * as never subscribed.
 */
final readonly class OwnerSubscriptionState
{
    public function __construct(
        private Trials $trials,
        private SubscriptionPresenter $presenter,
    ) {}

    /**
     * The owner's state. With a subscription it is presented from that; with none, from the owner itself, so an
     * owner on a generic trial resolves to GenericTrial rather than being read as never subscribed.
     *
     * `$pendingActivation` is the signal of a customer just back from checkout: while the subscription has not been
     * recorded yet, the owner resolves to Activating, a transient state a screen polls out of.
     */
    public function for(Model $owner, ?MerchantScope $merchant = null, bool $pendingActivation = false): SubscriptionState
    {
        return $this->presented($owner, $this->subscriptionOf($owner, $merchant), $pendingActivation);
    }

    /**
     * The state of an owner whose subscription the caller has already read with subscriptionOf(), so a screen that
     * shows the subscription as well asks the database once. Null reads the owner itself, as for() does for an owner
     * without a subscription.
     */
    public function presented(Model $owner, ?Subscription $subscription, bool $pendingActivation = false): SubscriptionState
    {
        if ($subscription instanceof Subscription) {
            return $this->presenter->present($subscription->toSnapshot(), $pendingActivation);
        }

        return $this->presenter->present($this->trials->ownerSnapshot($owner), $pendingActivation);
    }

    /**
     * The owner's newest subscription of the default type, with the platform or with the merchant given, or null
     * when there is no local row yet.
     */
    public function subscriptionOf(Model $owner, ?MerchantScope $merchant = null): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($owner)
            ->forMerchant($merchant)
            ->ofDefaultType()
            ->latest('id')
            ->first();
    }
}
