<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * A subscription moved to a new canonical state (the neutral form of a provider subscription update).
 * Carries the tier the subscription now maps to so the plan-sync effect can set the owner's tier
 * without a second lookup — null when the change conveys no tier (the effect then falls to zero).
 * `occurredAt` is the provider event's own timestamp (Unix seconds); the plan-sync effect uses it to
 * ignore a retried or out-of-order older delivery rather than regressing a newer state.
 *
 * The current cycle (`periodStart`/`periodEnd`, Unix seconds) rides along because metered usage is
 * accounted into the SUBSCRIPTION's cycle, not a calendar month — an owner who renews on the 31st has
 * no calendar month to bill into. Null when the provider conveys no cycle.
 *
 * `trialEnd` (Unix seconds) carries the subscription trial's end so the local row mirrors it — the trial
 * clock the in-app "trial ends soon" banner and the trial CTA read; a subscription trial otherwise leaves
 * no local date and every screen would read "0 days left". Null when the subscription is not trialing.
 *
 * `merchant` scopes the change to the seller it belongs to. A connected-account webhook is about the
 * creator's own subscription, and the plan-sync effect must write the creator's row — not the platform
 * row — or one creator's event would overwrite the tier a customer holds with another. Null is the
 * platform scope, byte-for-byte the single-seller behavior; the mapper stamps it from the firing account.
 *
 * `merchantAccountReference` is the PROVIDER account that issued the event — deliberately a second identity
 * from `merchant` (the docblock on MerchantScope explains why the two are kept apart). It exists for one
 * job: a provider customer id is unique only WITHIN its account, so the effect resolves the owner
 * account-scoped, never globally where the same id under another merchant would resolve to a stranger. Null
 * on a platform event (the global lookup is correct there); the merchant webhook mapper stamps it.
 */
final readonly class SubscriptionStateChanged implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public SubscriptionState $state,
        public ?string $subscriptionReference = null,
        public ?string $tierKey = null,
        public ?int $occurredAt = null,
        public ?int $periodStart = null,
        public ?int $periodEnd = null,
        public ?int $trialEnd = null,
        public ?MerchantScope $merchant = null,
        public ?string $merchantAccountReference = null,
    ) {}
}
