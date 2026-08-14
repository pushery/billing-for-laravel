<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantScopedSuspensionLadder;
use Pushery\Billing\Contracts\SuspensionLadder;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The concrete suspension ladder: reads the owner's delinquency clock from the local subscription row,
 * asks the dunning ladder which rung they are on now, and lets the SuspensionPolicy decide whether the
 * given surface is withdrawn at that rung. Everything keys on the stored timestamp — never a live
 * gateway status — so lockout keeps working during a provider outage and a not-yet-delinquent owner
 * (no clock) is never locked out.
 *
 * ## THE SUSPENSION IS PER MERCHANT — DECIDED, NOT ASSUMED
 *
 * A fan behind with creator A keeps creator B: arrears withdraw the surfaces of the merchant they are owed
 * to, and nothing else. The fan has a separate, performed contract with B, and B must not lose a paying
 * subscriber over an event B had no part in.
 *
 * This spent a while as an open question, and the shape of the wrong answers is worth keeping. The clock
 * used to come off the NEWEST row, so a row with no clock reset the ladder outright and a fan two rungs deep
 * walked back to zero by subscribing to anybody. That was corrected to the EARLIEST clock across the owner's
 * rows — which fixed the reset and kept the real defect, because both readings compute ACROSS rows, and
 * computing across rows is exactly what turns a debt owed to A into a lockout at B.
 *
 * The answer reads ONE row. Not the newest, not the earliest, not an aggregate: the row for the merchant
 * being asked about. A ladder that has to choose between rows is answering a question nobody asked.
 *
 * Omitting the merchant means the PLATFORM's own row, not "any of them" — `Subscription::forMerchant()`
 * collapses a null scope to the platform sentinel. In a single-seller install every row IS the platform's,
 * so this is byte-identical to what it always did.
 */
final readonly class LadderSuspension implements MerchantScopedSuspensionLadder, SuspensionLadder
{
    public function __construct(
        private ConfigDunningLadder $ladder,
        private SuspensionPolicy $policy,
    ) {}

    /** The unscoped contract: the platform's own relationship, which in a single-seller install is the only one. */
    public function isLockedOut(Model $owner, string $surface): bool
    {
        return $this->isLockedOutFor($owner, $surface, null);
    }

    public function isLockedOutFor(Model $owner, string $surface, ?MerchantScope $merchant): bool
    {
        $since = $this->delinquentSince($owner, $merchant);

        if (! $since instanceof DateTimeInterface) {
            return false;
        }

        return $this->policy->isLockedOut($surface, $this->ladder->currentLevel($since, Carbon::now()));
    }

    private function delinquentSince(Model $owner, ?MerchantScope $merchant): ?DateTimeInterface
    {
        // ONE row's clock — this merchant's — and no ordering, because there is nothing to order.
        //
        // The two previous versions both read across rows: first the newest (a fresh row with no clock reset
        // the ladder outright, so a fan two rungs deep walked back to zero by subscribing to anybody), then
        // the earliest (which fixed the reset by making the longest-standing debt govern every merchant at
        // once). Ordering was never the question. Any aggregate over rows makes a debt owed to one creator
        // decide another creator's surfaces, and that is what this scope removes.
        //
        // `forMerchant()` is the single place the (billable, merchant) selection is spelled; a null scope
        // collapses to the platform sentinel there, so a single-seller install reads exactly the row it
        // always read.
        $since = Subscription::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant($merchant)
            ->whereNotNull('delinquent_since')
            ->value('delinquent_since');

        return $since instanceof DateTimeInterface ? $since : null;
    }
}
