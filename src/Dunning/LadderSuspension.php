<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\SuspensionLadder;
use Pushery\Billing\Models\Subscription;

/**
 * The concrete suspension ladder: reads the owner's delinquency clock from the local subscription row,
 * asks the dunning ladder which rung they are on now, and lets the SuspensionPolicy decide whether the
 * given surface is withdrawn at that rung. Everything keys on the stored timestamp — never a live
 * gateway status — so lockout keeps working during a provider outage and a not-yet-delinquent owner
 * (no clock) is never locked out.
 */
final readonly class LadderSuspension implements SuspensionLadder
{
    public function __construct(
        private ConfigDunningLadder $ladder,
        private SuspensionPolicy $policy,
    ) {}

    public function isLockedOut(Model $owner, string $surface): bool
    {
        $since = $this->delinquentSince($owner);

        if (! $since instanceof DateTimeInterface) {
            return false;
        }

        return $this->policy->isLockedOut($surface, $this->ladder->currentLevel($since, Carbon::now()));
    }

    private function delinquentSince(Model $owner): ?DateTimeInterface
    {
        $subscription = Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return $subscription?->delinquent_since;
    }
}
