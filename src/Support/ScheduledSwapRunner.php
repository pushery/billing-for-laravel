<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;
use Throwable;

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
        //
        // Oldest first, and by id among equals, so every run meets the rows in the same order.
        $due = Subscription::model()::query()
            ->whereNotNull('scheduled_swap_at')
            ->where('scheduled_swap_at', '<=', $now)
            ->orderBy('scheduled_swap_at')
            ->orderBy('id')
            ->get();

        foreach ($due as $subscription) {
            try {
                if ($this->apply($subscription)) {
                    $applied++;
                }
            } catch (Throwable $failure) {
                // Logged and skipped, never rethrown, as the cycle does with a cycle it cannot process. Rethrown,
                // one row the driver refuses would stop every change due after it, on every run, and those
                // customers would go on paying the tier they left. The row keeps its schedule: a tier taken out of
                // the catalog, or a provider that failed this once, is for the operator to see and settle.
                Log::error('billing: a due plan change could not be applied', [
                    'subscription' => $subscription->getKey(),
                    'tier' => $subscription->scheduled_tier_key,
                    'reason' => $failure->getMessage(),
                ]);
            }
        }

        return $applied;
    }

    private function apply(Subscription $subscription): bool
    {
        $targetTier = $subscription->scheduled_tier_key;
        $owner = $targetTier === null ? null : $this->ownerOf($subscription);

        // Three ways a due row is not actionable, all cleared rather than retried forever: a malformed
        // schedule that has a date but no target tier, an orphaned one whose owner was deleted between
        // scheduling and the due date, and a contract that ended or was terminated in between, which takes
        // the change it had pending with it. Either way there is nothing to swap.
        if ($targetTier === null || ! $owner instanceof Model || $subscription->terminated() || $subscription->isReplaceableByANewSubscription()) {
            $subscription->cancelScheduledSwap();

            return false;
        }

        // The swap prorates, as it does on the in-app path, and the driver decides how. A provider that prorates
        // itself does it there, at the moment it is told. The local driver bills the period when it closes, so it
        // carries the tier being left up to the moment the change was scheduled for, which is where the period
        // it was deferred to begins: nothing when the cycle has already opened that period, and the whole of the
        // period before when that one is still being collected.
        //
        // This runner books no proration of its own before calling the swap: the swap applies, so a credit
        // booked here as well would be a second one.
        //
        // Only the default contract is prorated. The local strategy prices the owner, not a row: it reads the
        // tier and period the owner resolves to, which are the default contract's, so for any other type it
        // would credit the remainder of a different contract.
        //
        // And the swap addresses the row that carries the schedule, with its merchant and its contract type.
        // Without them a sponsorship's downgrade would land on the owner's default subscription at the platform.
        $type = $subscription->type;

        // Through applyScheduledSwap() wherever the driver offers it, because swap() asks the eligibility gate
        // first. The gate was asked when the change was scheduled; asked again here, where nobody is acting, a
        // gate that needs an acting person refuses every scheduled change, and the customer goes on paying the
        // tier they left. A later block reaches the change through the paths above: an owner deleted, a
        // contract ended. An application that blocks an account in a way of its own drops the change with
        // Subscription::cancelScheduledSwap().
        $prorate = $type === Subscription::TYPE_DEFAULT;
        $merchant = MerchantScope::fromUid($subscription->merchant_uid);

        if ($this->actions instanceof AppliesScheduledSwaps) {
            $this->actions->applyScheduledSwap($owner, $targetTier, prorate: $prorate, merchant: $merchant, type: $type);
        } else {
            $this->actions->swap($owner, $targetTier, prorate: $prorate, merchant: $merchant, type: $type);
        }

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
