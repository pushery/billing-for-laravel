<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\Exceptions\QuotaExceeded;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\QuotaDecision;

/**
 * The quota pre-check: given an owner, a meter and how much they want to consume, decide whether to
 * allow, degrade or block — according to the meter's policy. This is the enforcement the package always
 * had the primitive for (UsageMeter) but never applied; an app calls allows()/authorize() BEFORE doing
 * the work, then records the usage after.
 *
 * It is a point-in-time read, not a lock. Two truly simultaneous requests can both pass a boundary check
 * here — an owner one unit below their limit gets through as many times as they can fire requests at once.
 * That is inherent to asking rather than taking, and it is why an app that must not oversell meters through
 * UsageRecorder::meter(), which HOLDS the allowance under a row lock before the work runs. This gate is the
 * cheap pre-check in front of it, and what it guarantees is that the four policies DIFFER: hard-stop and
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
        private PrepaidLedger $prepaid,
    ) {}

    /** The quota decision for consuming $quantity of $meterKey now. An unmetered or ceiling-less meter is always allowed. */
    public function allows(Model $owner, string $meterKey, int $quantity = 1): QuotaDecision
    {
        $component = $this->meters->component($this->tiers->resolve($owner)->key, $meterKey);

        // No such meter on the tier, or a meter with no `included` ceiling: nothing to enforce.
        if (! $component instanceof MeteredComponent || $component->included === null) {
            $policy = $component instanceof MeteredComponent ? $component->policy : MeteringPolicy::FairUse;

            return new QuotaDecision($meterKey, $policy, allowed: true, degraded: false, remaining: null);
        }

        $period = $this->periods->forOwner($owner)->key;
        $spent = $this->counters->consumed($owner, $meterKey, $period);

        // What the owner still has is the cycle's unspent free allowance PLUS the units they bought. The
        // free allowance is spent first, so prepaid is what remains of it — measured the same way the
        // reservation measures it, or the cheap pre-check would disagree with the lock that enforces it.
        $remaining = max(0, $component->included - $spent) + $this->prepaid->balance($owner, $meterKey);
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

    /** Allow or throw: the same decision as allows(), but a blocked one raises QuotaExceeded. */
    public function authorize(Model $owner, string $meterKey, int $quantity = 1): QuotaDecision
    {
        $decision = $this->allows($owner, $meterKey, $quantity);

        if ($decision->blocked()) {
            throw QuotaExceeded::onMeter($meterKey, $decision->policy, $quantity, $decision->remaining ?? 0);
        }

        return $decision;
    }
}
