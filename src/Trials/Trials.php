<?php

declare(strict_types=1);

namespace Pushery\Billing\Trials;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\SubscriptionSnapshot;

/**
 * The generic trial: a free trial an owner is on WITHOUT a subscription — "try Pro for 14 days, no card".
 *
 * It lives on the owner's own `trial_ends_at` column (the same one Cashier reserves), not a subscription
 * row, because there is no subscription yet. The state presenter reads it through {@see ownerSnapshot()} so
 * an owner mid-generic-trial resolves to the GenericTrial state (which grants access), and the tier resolver
 * unlocks the configured trial tier (`billing.trial.generic_tier`) while it runs. When it lapses with no
 * subscription and no provider customer, the owner falls to `none`; with a customer on file, to `churned`.
 *
 * A generic trial only exists when a tier is configured for it: without `generic_tier` there is nothing for
 * it to unlock, so granting one is a no-op rather than an entitlement to nothing.
 */
final readonly class Trials
{
    public function __construct(
        private TrialPolicy $policy,
        private Repository $config,
    ) {}

    /**
     * Start a generic trial for an owner that has no subscription. Returns the trial end, or null when there
     * is no trial to grant (policy disabled, no configured trial tier) — and is idempotent: an owner already
     * on a generic trial keeps their existing end date rather than having the clock reset under them.
     */
    public function grant(Model $owner): ?CarbonInterface
    {
        if ($this->genericTier() === null) {
            return null; // no tier to unlock — nothing to grant
        }

        $existing = $this->endsAt($owner);

        if ($existing instanceof CarbonInterface) {
            return $existing; // idempotent — keep the running trial's end
        }

        // The policy computes the end; a disabled policy (trial_days 0) returns null and grants nothing.
        $policyEnd = $this->policy->endsAt(CarbonImmutable::now());

        if (! $policyEnd instanceof DateTimeImmutable) {
            return null;
        }

        $endsAt = CarbonImmutable::createFromInterface($policyEnd);
        $owner->forceFill(['trial_ends_at' => $endsAt])->save();

        return $endsAt;
    }

    /** Whether the owner is currently on a generic trial (a future trial end, and a tier to unlock). */
    public function onGenericTrial(Model $owner): bool
    {
        return $this->genericTier() !== null && $this->endsAt($owner) instanceof CarbonInterface;
    }

    /** The trial's end, or null when it is absent or already past. */
    public function endsAt(Model $owner): ?CarbonInterface
    {
        $endsAt = $this->trialEndsAtColumn($owner);

        return $endsAt instanceof CarbonInterface && $endsAt->isFuture() ? $endsAt : null;
    }

    /** The tier a generic trial unlocks, or null when the app configures none (resolved by the policy). */
    public function genericTier(): ?string
    {
        return $this->policy->genericTier();
    }

    /**
     * The driver-neutral snapshot for an owner with NO subscription, from which the presenter reads the
     * generic-trial / churned / none state. This is the path that was missing: nothing built a
     * no-subscription snapshot, so the presenter's generic-trial branch could never be reached for a real
     * owner.
     */
    public function ownerSnapshot(Model $owner): SubscriptionSnapshot
    {
        $onTrial = $this->onGenericTrial($owner);

        return new SubscriptionSnapshot(
            subscribed: $onTrial,
            hasSubscription: false,
            onGenericTrial: $onTrial,
            hasCustomerId: $this->hasCustomerReference($owner),
        );
    }

    private function trialEndsAtColumn(Model $owner): ?CarbonInterface
    {
        $value = $owner->getAttribute('trial_ends_at');

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function hasCustomerReference(Model $owner): bool
    {
        $column = $this->config->get('billing.customer.column', 'stripe_id');
        $reference = $owner->getAttribute(is_string($column) ? $column : 'stripe_id');

        return is_string($reference) && $reference !== '';
    }
}
