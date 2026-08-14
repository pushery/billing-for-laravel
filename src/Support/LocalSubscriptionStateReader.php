<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\SubscriptionStateReader;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\SubscriptionGrant;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The default subscription-state reader: it answers entirely from the local subscription rows, through the
 * same toSnapshot -> presenter -> state path every account screen uses, and never calls a provider.
 *
 * That provider-freedom is the point: a content-ACL check runs on a hot path, once per protected thing, so
 * it must not fan out to a payment provider — the state it needs is already local, mirrored there by the
 * webhook. The merchant scope keys each read on the sentinel, so a null scope reads the platform's own
 * subscription exactly as before, and grantsFor answers the whole marketplace in one indexed query rather
 * than a lookup per creator.
 */
final readonly class LocalSubscriptionStateReader implements SubscriptionStateReader
{
    public function __construct(
        private SubscriptionPresenter $presenter,
        private MerchantCatalog $catalogs,
    ) {}

    public function activeOn(Model $customer, ?MerchantScope $merchant = null, ?int $atLevel = null, ?CarbonInterface $at = null): bool
    {
        $grant = $this->grantOn($customer, $merchant, $at);

        if (! $grant instanceof SubscriptionGrant) {
            return false;
        }

        $granted = $atLevel === null ? $grant->grantsAccess() : $grant->atLeast($atLevel);

        return $granted && $grant->coversInstant($at ?? Carbon::now());
    }

    public function grantOn(Model $customer, ?MerchantScope $merchant = null, ?CarbonInterface $at = null): ?SubscriptionGrant
    {
        $subscription = Subscription::query()
            ->forOwner($customer)
            ->forMerchant($merchant)
            ->ofDefaultType()
            ->latest('id')
            ->first();

        return $subscription instanceof Subscription ? $this->grantFrom($subscription) : null;
    }

    public function grantsFor(Model $customer, ?CarbonInterface $at = null): array
    {
        $moment = $at ?? Carbon::now();

        // One indexed read of every merchant's row — never a query or a provider call per merchant. The
        // (owner, type, merchant_uid) unique already gives exactly one row per merchant, so there is nothing
        // to dedupe: each grant is keyed by its own merchant uid.
        $subscriptions = Subscription::query()
            ->forOwner($customer)
            ->ofDefaultType()
            ->get();

        $out = [];

        foreach ($subscriptions as $subscription) {
            $grant = $this->grantFrom($subscription);

            if ($grant->grantsAccess() && $grant->coversInstant($moment)) {
                $out[$subscription->merchant_uid] = $grant;
            }
        }

        return $out;
    }

    private function grantFrom(Subscription $subscription): SubscriptionGrant
    {
        $merchant = $subscription->merchant_type !== null && $subscription->merchant_id !== null
            ? new MerchantScope($subscription->merchant_type, $subscription->merchant_id)
            : null;

        $tierKey = $subscription->tier_key;

        // An unknown or absent tier ranks -1 — below every real tier — so a cumulative check fails closed on
        // it rather than granting the zero tier's access by accident.
        $tier = (is_string($tierKey) ? $this->catalogs->tierCatalog($merchant)->find($tierKey) : null)
            ?? new TierIdentity('unknown', 'unknown', level: -1);

        return new SubscriptionGrant(
            merchant: $merchant,
            tier: $tier,
            state: $this->presenter->present($subscription->toSnapshot()),
            windowStart: $subscription->current_period_start,
            windowEnd: $subscription->current_period_end,
        );
    }
}
