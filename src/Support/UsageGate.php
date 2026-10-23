<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\Exceptions\QuotaExceeded;
use Pushery\Billing\Exceptions\SpendCapExceeded;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\QuotaDecision;
use Pushery\Billing\ValueObjects\SpendCapCheck;

/**
 * The quota pre-check: given an owner, a meter and how much they want to consume, decide whether to
 * allow, degrade or block — according to the meter's policy. This is the enforcement the package always
 * had the primitive for (UsageMeter) but never applied; an app calls allows()/authorize() before doing
 * the work, then records the usage after.
 *
 * It is a point-in-time read, not a lock. Two truly simultaneous requests can both pass a boundary check
 * here — an owner one unit below their limit gets through as many times as they can fire requests at once.
 * That is inherent to asking rather than taking, and it is why an app that must not oversell meters through
 * UsageRecorder::meter(), which holds the allowance under a row lock before the work runs. This gate is the
 * cheap pre-check in front of it, and what it guarantees is that the four policies differ: hard-stop and
 * refuse block, degrade serves but flags, fair-use never blocks.
 *
 * It counts held units against the allowance, not just used ones: allowance promised to a request that is
 * still in flight is allowance the owner no longer has, and a gate blind to that would wave through the
 * very request the hold exists to refuse.
 */
final readonly class UsageGate
{
    public function __construct(
        private MeterCatalog $meters,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private UsageMeter $counters,
        private SpendCaps $caps,
    ) {}

    /**
     * The quota decision for consuming $quantity of $meterKey now. An unmetered or ceiling-less meter is always allowed
     * by its allowance. With spend caps on, the owner's cap can still refuse the request, and the decision carries what
     * is left under the cap.
     */
    public function allows(Model $owner, string $meterKey, int $quantity = 1): QuotaDecision
    {
        return $this->decide($owner, $meterKey, $quantity)[0];
    }

    /**
     * Allow or throw: the same decision as allows(), but a blocked one raises QuotaExceeded, or SpendCapExceeded where
     * the owner's spend cap refused it.
     */
    public function authorize(Model $owner, string $meterKey, int $quantity = 1): QuotaDecision
    {
        [$decision, $refusal] = $this->decide($owner, $meterKey, $quantity);

        if ($refusal instanceof SpendCapCheck) {
            throw SpendCapExceeded::fromCheck($meterKey, $decision->policy, $decision->remaining ?? 0, $refusal);
        }

        if ($decision->blocked()) {
            throw QuotaExceeded::onMeter($meterKey, $decision->policy, $quantity, $decision->remaining ?? 0);
        }

        return $decision;
    }

    /**
     * The decision, and the spend cap check that refused it where the cap did.
     *
     * The allowance decides first, by the meter's policy, and a request it refuses stays refused whatever the cap says.
     * A request it takes is then measured against the owner's spend cap, and the first stop of a period is announced,
     * as SpendCaps describes.
     *
     * @return array{0: QuotaDecision, 1: ?SpendCapCheck}
     */
    private function decide(Model $owner, string $meterKey, int $quantity): array
    {
        $component = $this->meters->component($this->tiers->resolve($owner)->key, $meterKey);
        $decision = $this->allowance($owner, $meterKey, $quantity, $component);

        if (! $component instanceof MeteredComponent || ! $this->caps->enabled()) {
            return [$decision, null];
        }

        $period = $this->periods->forOwner($owner);
        $check = $this->caps->check($owner, $component, $quantity, $period);

        if (! $decision->blocked() && ! $check->admits()) {
            $this->caps->announce($owner, $check, $period);

            return [new QuotaDecision($meterKey, $decision->policy, allowed: false, degraded: false, remaining: $decision->remaining, remainingSpend: $check->remaining()), $check];
        }

        return [new QuotaDecision($meterKey, $decision->policy, $decision->allowed, $decision->degraded, $decision->remaining, $check->remaining()), null];
    }

    /** The decision of the meter's allowance alone, by its policy. */
    private function allowance(Model $owner, string $meterKey, int $quantity, ?MeteredComponent $component): QuotaDecision
    {
        // No such meter on the tier, or a meter with no `included` ceiling: nothing to enforce.
        if (! $component instanceof MeteredComponent || $component->included === null) {
            $policy = $component instanceof MeteredComponent ? $component->policy : MeteringPolicy::FairUse;

            return new QuotaDecision($meterKey, $policy, allowed: true, degraded: false, remaining: null);
        }

        $period = $this->periods->forOwner($owner)->key;
        // Measured the same way the reservation measures it, by the same method. Arithmetic of its own here
        // would part company with the reservation exactly where it matters: with no free allowance the hold
        // would reduce nothing, so the gate would let through requests the lock then refuses, and hand out
        // bought units twice.
        $remaining = $this->counters->remaining($owner, $meterKey, $period, $component->included);
        $withinAllowance = $quantity <= $remaining;

        if ($withinAllowance) {
            return new QuotaDecision($meterKey, $component->policy, allowed: true, degraded: false, remaining: $remaining);
        }

        // Past the allowance — the policy decides what that means.
        return match ($component->policy) {
            MeteringPolicy::HardStop, MeteringPolicy::Refuse => new QuotaDecision($meterKey, $component->policy, allowed: false, degraded: false, remaining: $remaining),
            MeteringPolicy::Degrade => new QuotaDecision($meterKey, $component->policy, allowed: true, degraded: true, remaining: $remaining),
            MeteringPolicy::FairUse => new QuotaDecision($meterKey, $component->policy, allowed: true, degraded: false, remaining: $remaining),
        };
    }
}
