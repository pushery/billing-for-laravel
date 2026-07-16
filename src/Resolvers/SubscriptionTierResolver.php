<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\SubscriptionPresenter;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * Resolves a billable's tier from its local subscription's tier_key — but only while the
 * subscription actually grants access (active/trialing/grace). A past-due or incomplete subscription
 * grants nothing, so this hard-dunning resolver falls to the zero-tier, as does a missing/unknown
 * tier. A resolver never implies access.
 *
 * An owner on a GENERIC trial has no subscription at all, yet is entitled — so when there is no
 * subscription row, the configured trial tier is unlocked for the length of the trial. Crucially the
 * generic trial only applies in the ABSENCE of a subscription: once a subscription exists its state
 * governs, so a past-due/incomplete subscriber whose owner trial clock still happens to be in the future
 * is NOT rescued back to the paid tier — that would defeat this resolver's own hard-dunning contract.
 */
final readonly class SubscriptionTierResolver implements TierResolver
{
    public function __construct(
        private Repository $config,
        private TierCatalog $catalog,
        private SubscriptionPresenter $presenter,
        private Trials $trials,
    ) {}

    public function resolve(Model $billable): TierIdentity
    {
        $subscription = Subscription::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        if ($subscription instanceof Subscription) {
            $tierKey = $subscription->tier_key;

            if ($this->presenter->present($subscription->toSnapshot())->grantsAccess() && is_string($tierKey)) {
                $tier = $this->catalog->find($tierKey);

                if ($tier instanceof TierIdentity) {
                    return $tier;
                }
            }

            // A subscription exists — its state is the authority. A generic trial is a trial taken
            // WITHOUT a subscription, so a blocking (past-due/incomplete) or unknown-tier subscription
            // falls to the zero tier here and is never rescued by a lingering owner trial clock.
            return $this->zeroTier();
        }

        // No subscription at all: a generic trial (the owner's own trial clock) unlocks its configured
        // tier while it runs.
        $genericTier = $this->trials->genericTier();

        if ($genericTier !== null && $this->trials->onGenericTrial($billable)) {
            $tier = $this->catalog->find($genericTier);

            if ($tier instanceof TierIdentity) {
                return $tier;
            }
        }

        return $this->zeroTier();
    }

    private function zeroTier(): TierIdentity
    {
        $zero = $this->config->get('billing.zero_tier', 'free');
        $zero = is_string($zero) ? $zero : 'free';

        return $this->catalog->find($zero) ?? new TierIdentity($zero, $zero);
    }
}
