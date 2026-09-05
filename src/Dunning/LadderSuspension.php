<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ArrearsClock;
use Pushery\Billing\Contracts\MerchantScopedSuspensionLadder;
use Pushery\Billing\Contracts\SuspensionLadder;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The concrete suspension ladder: asks the {@see ArrearsClock} since when the owner is behind with this
 * merchant, asks the dunning ladder which rung they are on now, and lets the SuspensionPolicy decide
 * whether the given surface is withdrawn at that rung. Everything keys on a stored timestamp — never a live
 * gateway status — so lockout keeps working during a provider outage and a not-yet-delinquent owner
 * (no clock) is never locked out.
 *
 * ## THE CLOCK ARRIVES THROUGH A SEAM, AND THAT IS WHAT MAKES THE LADDER REUSABLE
 *
 * This class used to read `billing_subscriptions` itself, which chained the whole ladder to this package's
 * schema. Everything else in the ladder was already free of it — the rungs are configuration, the policy is
 * configuration, the cure window is a division — so one column read was the only thing standing between a
 * consumer and several-rung dunning with per-surface withdrawal. They wrote their own single deadline
 * instead, which is the poorer mechanism, and did it because they could not reach this one.
 *
 * The reading that used to be here now lives in {@see LocalArrearsClock}, bound by
 * default, so nothing changes for an install that keeps the package's schema.
 *
 * ## THE SUSPENSION IS PER MERCHANT — DECIDED, NOT ASSUMED
 *
 * A fan behind with creator A keeps creator B: arrears withdraw the surfaces of the merchant they are owed
 * to, and nothing else. The fan has a separate, performed contract with B, and B must not lose a paying
 * subscriber over an event B had no part in.
 *
 * The scope is therefore part of the QUESTION the clock is asked, not a filter applied to its answer — so
 * an implementation has no aggregate to get wrong. This package got it wrong twice while the reading lived
 * here; the two wrong answers, and why ordering was never the issue, are kept where the reading now is.
 *
 * Omitting the merchant means the PLATFORM's own relationship, not "any of them". In a single-seller
 * install there is only ever the one.
 */
final readonly class LadderSuspension implements MerchantScopedSuspensionLadder, SuspensionLadder
{
    public function __construct(
        private ConfigDunningLadder $ladder,
        private SuspensionPolicy $policy,
        private ArrearsClock $clock,
    ) {}

    /** The unscoped contract: the platform's own relationship, which in a single-seller install is the only one. */
    public function isLockedOut(Model $owner, string $surface): bool
    {
        return $this->isLockedOutFor($owner, $surface, null);
    }

    public function isLockedOutFor(Model $owner, string $surface, ?MerchantScope $merchant): bool
    {
        $since = $this->clock->delinquentSince($owner, $merchant);

        if (! $since instanceof DateTimeInterface) {
            return false;
        }

        return $this->policy->isLockedOut($surface, $this->ladder->currentLevel($since, Carbon::now()));
    }
}
