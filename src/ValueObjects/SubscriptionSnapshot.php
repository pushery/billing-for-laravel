<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A driver-neutral snapshot of a billable's subscription predicates at one instant, from which the
 * SubscriptionPresenter collapses a single canonical SubscriptionState. Each driver builds this from
 * its own subscription model (the Stripe driver from a Cashier subscription, the Mollie/Adyen local
 * engine from the local subscription row) — so the presenter stays pure and provider-agnostic.
 *
 * When {@see $hasSubscription} is true the status predicates below it (incomplete, pastDue, …) are the
 * meaningful ones; when it is false there is no subscription and the two no-subscription flags apply
 * instead — {@see $onGenericTrial} (a trial with no subscription) and {@see $hasCustomerId} (whether a
 * provider customer exists, which distinguishes a churned owner from one who never subscribed).
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
