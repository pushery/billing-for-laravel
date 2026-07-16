<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\ValueObjects\SubscriptionSnapshot;

/**
 * Collapses a driver-neutral SubscriptionSnapshot's overlapping predicates into exactly one
 * canonical SubscriptionState, so every account screen renders the state matrix identically.
 *
 * Precedence matters because predicates overlap (a grace subscription is also active): the most
 * specific state wins. `$pendingActivation` is the optimistic post-checkout flag (no positive
 * signal has landed yet) — it yields only while the owner is not already subscribed.
 *
 * Pure and provider-agnostic: each driver produces the snapshot, the presenter never touches a
 * provider object.
 */
final class SubscriptionPresenter
{
    public function present(SubscriptionSnapshot $snapshot, bool $pendingActivation = false): SubscriptionState
    {
        if ($pendingActivation && ! $snapshot->subscribed) {
            return SubscriptionState::Activating;
        }

        if (! $snapshot->hasSubscription) {
            if ($snapshot->onGenericTrial) {
                return SubscriptionState::GenericTrial;
            }

            return $snapshot->hasCustomerId ? SubscriptionState::Churned : SubscriptionState::None;
        }

        return match (true) {
            $snapshot->incompleteExpired => SubscriptionState::IncompleteExpired,
            $snapshot->incomplete => SubscriptionState::Incomplete,
            $snapshot->pastDue => SubscriptionState::PastDue,
            // Ahead of grace/trial/active on purpose: a provider that pauses collection may leave every
            // one of those predicates true, and a paused subscription raises no invoice — so reading it
            // as "paying" is the difference between a customer being billed and being served for free.
            $snapshot->paused => SubscriptionState::Paused,
            $snapshot->onGracePeriod => SubscriptionState::Grace,
            $snapshot->onTrial => SubscriptionState::Trialing,
            $snapshot->active => SubscriptionState::Active,
            default => SubscriptionState::Ended,
        };
    }
}
