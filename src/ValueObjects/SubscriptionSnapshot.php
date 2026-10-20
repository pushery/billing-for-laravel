<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A driver-neutral snapshot of a billable's subscription predicates at one instant, from which the
 * SubscriptionPresenter collapses a single canonical SubscriptionState. Each driver builds this from
 * its own subscription model (the Stripe driver from a Cashier subscription, the local
 * engine from the local subscription row) — so the presenter stays pure and provider-agnostic.
 *
 * When {@see $hasSubscription} is true the status predicates below it (incomplete, pastDue, …) are the
 * meaningful ones; when it is false there is no subscription and {@see $onGenericTrial} (a trial with no
 * subscription) applies instead. {@see $hasCustomerId} says whether the provider knows the owner as a
 * customer. It decides no state: a one-time purchase or a saved card makes a customer of somebody who never
 * subscribed.
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        public bool $subscribed,
        public bool $hasSubscription,
        public bool $onGenericTrial = false,
        public bool $hasCustomerId = false,
        public bool $incompleteExpired = false,
        public bool $incomplete = false,
        public bool $pastDue = false,
        public bool $onGracePeriod = false,
        public bool $onTrial = false,
        public bool $active = false,
        public bool $paused = false,
    ) {}
}
