<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Contracts\MerchantScopedDunningGuard;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\SubscriptionPresenter;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The hot-path dunning gate. It reads ONLY the owner's local subscription row (no provider call),
 * collapses it to a state via the presenter, and returns that state when it blocks
 * (past-due/incomplete) or null otherwise — so a synced past_due subscription cannot silently grant
 * the free allowance.
 *
 * ## THE LOCKOUT IS PER MERCHANT — DECIDED, NOT ASSUMED
 *
 * A fan who falls behind with creator A keeps creator B. The fan has a separate, performed contract with B;
 * withdrawing B's service over a debt owed to A would be a statement about the platform that nobody meant
 * to make, and B would lose a paying subscriber over an event B had no part in.
 *
 * The reading was open for a while, and the wrong answers are worth keeping because they were subtle. The
 * query first read the NEWEST row and prose called that platform-wide — but which row is newest is insertion
 * order, so the gate was per-creator, platform-wide or absent depending on what the fan last subscribed to,
 * including the case where a fan already past due platform-side subscribed to a creator and stopped being
 * blocked at all. Then it read EVERY row, which was genuinely platform-wide and genuinely the collateral
 * damage above.
 *
 * Both were aggregates over rows, and that is the shape the scope removes: an aggregate lets a debt owed to
 * one merchant decide another merchant's access. This reads the row for the merchant being asked about.
 *
 * Omitting the merchant means the PLATFORM's own row — `Subscription::forMerchant()` collapses a null scope
 * to the platform sentinel, the same reading every other scoped contract here takes. In a single-seller
 * install every row is the platform's, so the behavior is byte-identical to what it always was.
 *
 * The dunning ladder keeps its teeth per relationship rather than across them: arrears withdraw the
 * merchant's own surfaces immediately, and the cure window that follows is a separate mechanism.
 *
 * One thing the earlier version of this comment got right and is worth restating: documented intent is not
 * behavior, and prose is the one part of a package that never goes red when it stops being true.
 */
final readonly class LocalDunningGuard implements DunningGuard, MerchantScopedDunningGuard
{
    public function __construct(private SubscriptionPresenter $presenter) {}

    /** The unscoped contract: the platform's own relationship, which in a single-seller install is the only one. */
    public function blockingState(Model $owner): ?SubscriptionState
    {
        return $this->blockingStateFor($owner, null);
    }

    public function blockingStateFor(Model $owner, ?MerchantScope $merchant): ?SubscriptionState
    {
        // THIS merchant's rows. Not the newest row, and not every row.
        //
        // `latest('id')->first()` was the first version and it was a fourth reading nobody chose: the row it
        // picks depends on insertion order, so a fan past due on the PLATFORM plan who then subscribed to any
        // creator got a newer row, the guard read that one, found it active and returned null — dunning
        // stopped, the paid allowance kept flowing, nothing logged, no test red. Reading EVERY row fixed that
        // and produced the opposite harm: one creator's arrears blocked all of them.
        //
        // The scope is what makes the question answerable at all. "Is this owner blocked" has no answer in a
        // marketplace; "is this owner blocked AT THIS MERCHANT" does, and it needs no aggregate.
        $states = Subscription::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant($merchant)
            ->get()
            ->map(fn (Subscription $subscription): SubscriptionState => $this->presenter->present($subscription->toSnapshot()))
            ->filter(fn (SubscriptionState $state): bool => $state->isBlocking());

        // The first blocking state in row order. Which blocking state is reported matters less than THAT one
        // is: every caller asks "is this owner blocked", and past-due and incomplete both mean yes.
        return $states->first();
    }
}
