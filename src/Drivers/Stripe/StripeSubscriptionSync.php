<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Stripe\StripeClient;

/**
 * Reads the billable's current Stripe subscription and maps it — through the SAME StripeSubscriptionMapper
 * the webhook uses — to the neutral event the plan-sync effect applies. This is the return-reconcile: the
 * customer is back from the hosted checkout, and this writes the local row now instead of waiting on the
 * webhook.
 *
 * It reads EVERY subscription the customer has and picks the one that is actually alive, rather than the
 * one created most recently. Stripe lists newest-first, and customers accumulate dead subscriptions: an
 * abandoned checkout leaves an `incomplete_expired` subscription behind, and it is NEWER than the paying
 * subscription it was never meant to replace. Taking the newest handed the plan-sync effect a lapsed
 * subscription for a paying customer — and a state that grants no access pulls the tier to zero. They
 * would keep paying, on the free tier, until something else moved their subscription.
 *
 * No `expand` is sent: a subscription item's `price` is already expanded by default, and asking Stripe to
 * expand an already-expanded path is an error. The subscription's own `created` stamp becomes the event
 * time, so a first reconcile wins the empty local row but still loses to a newer webhook state.
 */
final readonly class StripeSubscriptionSync implements SubscriptionSync
{
    /** One customer's subscriptions, the dead ones included — far more than anyone accumulates. */
    private const int PAGE = 100;

    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private StripeSubscriptionMapper $mapper,
    ) {}

    public function pull(Model $billable): ?SubscriptionStateChanged
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return null;
        }

        $subscriptions = $this->stripe->subscriptions->all([
            'customer' => $customerId,
            'status' => 'all',
            'limit' => self::PAGE,
        ]);

        $best = null;
        $bestRank = -1;
        $bestCreated = -1;

        foreach ($subscriptions->data as $candidate) {
            $array = $candidate->toArray();
            $created = is_int($array['created'] ?? null) ? $array['created'] : 0;
            $event = $this->mapper->toEvent($array, $created);

            if (! $event instanceof SubscriptionStateChanged) {
                continue;
            }

            $rank = $this->rank($event->state);

            // The liveliest wins; among equals, the newest. Never the newest outright.
            if ($rank > $bestRank || ($rank === $bestRank && $created > $bestCreated)) {
                $best = $event;
                $bestRank = $rank;
                $bestCreated = $created;
            }
        }

        return $best;
    }

    /**
     * How ALIVE a subscription is. A customer can hold several at once — a paying one plus the wreckage of
     * an abandoned checkout — and there is one local row to describe them, so the liveliest has to win it.
     */
    private function rank(SubscriptionState $state): int
    {
        return match ($state) {
            // Standing at the provider: it bills, or would bill again the moment the owner unpaused it.
            SubscriptionState::Active,
            SubscriptionState::Trialing,
            SubscriptionState::Grace,
            SubscriptionState::Paused => 2,
            // Standing, but not paying. Still the owner's subscription — dunning depends on seeing it.
            SubscriptionState::PastDue,
            SubscriptionState::Incomplete => 1,
            // Wreckage: canceled, lapsed, or an abandoned checkout. Chosen only when it is all there is.
            default => 0,
        };
    }
}
