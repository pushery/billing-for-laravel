<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\SubscriptionPresenter;

/**
 * The hot-path dunning gate. It reads ONLY the owner's local subscription row (no provider call),
 * collapses it to a state via the presenter, and returns that state when it blocks
 * (past-due/incomplete) or null otherwise — so a synced past_due subscription cannot silently grant
 * the free allowance.
 */
final readonly class LocalDunningGuard implements DunningGuard
{
    public function __construct(private SubscriptionPresenter $presenter) {}

    public function blockingState(Model $owner): ?SubscriptionState
    {
        $subscription = Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $state = $this->presenter->present($subscription->toSnapshot());

        return $state->isBlocking() ? $state : null;
    }
}
