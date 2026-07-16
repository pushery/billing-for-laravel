<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SubscriptionSync;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Webhooks\Effects\SyncPlanFromSubscription;

/**
 * Reconciles the billable's subscription against the provider on demand — the return path after a hosted
 * checkout. It pulls the current provider subscription and applies it through the SAME plan-sync effect
 * the webhook uses, so the local row is written the instant the customer is back, not whenever the
 * webhook happens to arrive. It carries no rule of its own: the effect owns recency, the zero-tier pull,
 * the untouchable tiers and the create-race convergence.
 *
 * It applies to the KNOWN owner directly (not via the customer reference), so it works even on an install
 * that has not configured billing.customer — the exact case where the webhook path would find nobody.
 */
final readonly class SubscriptionReconciler
{
    public function __construct(
        private SubscriptionSync $sync,
        private SyncPlanFromSubscription $apply,
    ) {}

    /** Pull and apply the owner's current provider subscription; returns the resolved state, or null when there is none. */
    public function syncFromProvider(Model $owner): ?SubscriptionState
    {
        $event = $this->sync->pull($owner);

        if (! $event instanceof SubscriptionStateChanged) {
            return null;
        }

        $this->apply->applyTo($owner, $event);

        return $event->state;
    }
}
