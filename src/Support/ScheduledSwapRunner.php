<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Executes the plan changes that were scheduled for later — a downgrade waiting for the period it was
 * deferred to. A due swap is simply the normal swap performed at the moment it comes due, so this is
 * driver-neutral: whatever SubscriptionActions does for an immediate swap is what a scheduled one becomes
 * when its date arrives.
 *
 * It runs from the billing cycle tick (billing:run), which the local engine already fires on a schedule.
 * Under Stripe the provider drives its own cycle, but a locally-scheduled downgrade still has to be applied
 * to the provider when it comes due, so this runs regardless of driver.
 */
final readonly class ScheduledSwapRunner
{
    public function __construct(
        private SubscriptionActions $actions,
        private BillingEventLog $log,
        private PlanCatalog $plans,
        private ProrationStrategy $proration,
    ) {}

    /**
     * Apply every scheduled swap that has come due, and return how many were applied.
     *
     * Due means the effective moment is now or in the past. The schedule is cleared as part of the same
     * step so a second run cannot apply it again — a downgrade executed twice would move a customer past
     * the tier they chose.
     */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now()->utc();
        $applied = 0;

        // Selected by the due DATE: a NULL scheduled_swap_at fails the comparison in SQL, so only rows with
        // a real, past effective moment come through. A well-formed schedule always has a tier alongside the
        // date, but a malformed one (a legacy or partial write) is caught in apply() rather than silently
        // skipped by a tier filter here.
        $due = Subscription::query()
            ->whereNotNull('scheduled_swap_at')
            ->where('scheduled_swap_at', '<=', $now)
            ->get();

        foreach ($due as $subscription) {
            if ($this->apply($subscription)) {
                $applied++;
            }
        }

        return $applied;
    }

    private function apply(Subscription $subscription): bool
    {
        $targetTier = $subscription->scheduled_tier_key;
        $owner = $targetTier === null ? null : $this->ownerOf($subscription);

        // Two ways a due row is not actionable, both cleared rather than retried forever: a malformed
        // schedule that has a date but no target tier, and an orphaned one whose owner was deleted between
        // scheduling and the due date. Either way there is nothing to swap.
        if ($targetTier === null || ! $owner instanceof Model) {
            $subscription->cancelScheduledSwap();

            return false;
        }

        // The proration runs first, for the same reason as on the in-app path: it prices the unused
        // remainder against the tier resolved AT THAT MOMENT, and after the swap that is already the new
        // one. A scheduled downgrade lands at the period end, where the remainder is normally zero and the
        // strategy writes nothing — but "normally" is doing work in that sentence. The runner fires from the
        // cycle tick, so it can arrive minutes early on a busy schedule, and a genuine remainder there is
        // owed just the same. Calling it unconditionally costs a no-op and removes the assumption.
        $plan = $this->plans->planFor($targetTier);

        if ($plan instanceof Plan) {
            $this->proration->applySwap($owner, $plan);
        }

        $this->actions->swap($owner, $targetTier);
        $subscription->cancelScheduledSwap();

        $this->log->record('billing.scheduled_swap_applied', $owner, [
            'tier' => $targetTier,
        ], AuditSource::System);

        return true;
    }

    /** Resolve the subscription's owner back to a model via the morph map (mirrors AdvanceDunningCommand). */
    private function ownerOf(Subscription $subscription): ?Model
    {
        $class = Relation::getMorphedModel($subscription->owner_type) ?? $subscription->owner_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($subscription->owner_id);

        return $owner instanceof Model ? $owner : null;
    }
}
