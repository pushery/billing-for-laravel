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
 *
 * `declarationReference` is the key the buyer's withdrawal declarations were recorded under, read back off the
 * subscription the checkout stamped it on, so the local row can carry it and the ledger can find the
 * declarations for this subscription. Null when the subscription carries none, the ordinary case on every
 * install without a consumer-rights profile.
 *
 * `startedAt` (Unix seconds) is when the provider says the subscription began, so the local row can hold the day a
 * subscriber's withdrawal window opens. Null when the provider conveys none.
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
        public ?string $declarationReference = null,
        public ?int $startedAt = null,
        /**
         * The LOCAL code of a minted merchant coupon the provider applied, or null.
         *
         * Null is the common and correct answer. It means one of two things, and neither is a gap: no
         * discount was applied at all, or the discount came from the CATALOG (`billing.coupons`), where a
         * human created the provider coupon with its own `max_redemptions` and the provider is the authority.
         * A minted merchant coupon is the one case where this package is the authority and cannot reach the
         * redemption itself, because the hosted checkout applies the discount inside the provider's session.
         *
         * So this is the local code rather than the provider id: a consumer books the redemption against
         * their own row, and doing that from an id would mean the provider arithmetic this package exists to
         * encapsulate. See StripePlatformCouponProvisioner for why the limit is enforced locally and what
         * that requires of a hosted consumer.
         */
        public ?string $couponCode = null,
    ) {}
}
