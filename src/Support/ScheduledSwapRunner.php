<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Consumer\AnnualTerms;
use Pushery\Billing\Consumer\CustomerTypes;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\ArrangesAnnualTerms;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Exceptions\SwapAwaitsPayment;
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
 * Under Stripe the provider drives its own cycle and renews at the boundary itself, so a change applied when
 * it comes due would follow an invoice for another period of the old plan. Where the driver binds
 * ArrangesAnnualTerms, a scheduled change is therefore arranged at the provider a few days ahead, and the
 * provider makes it at the boundary. A change that could not be arranged is applied when it comes due, as
 * under every other driver, so this runs regardless of driver.
 */
final readonly class ScheduledSwapRunner
{
    /** How many days before a scheduled change comes due it is arranged at a provider that renews on its own. */
    public const int ARRANGE_AHEAD_DAYS = 3;

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

        $this->arrangeAhead($now);

        // Selected by the due date: a NULL scheduled_swap_at fails the comparison in SQL, so only rows with
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

        // Arranged at the provider ahead of time, and still held by the arrangement: the provider made the change at the
        // boundary, and its event tells the row. Only the schedule on the row is left to clear, since a swap here would
        // make the change a second time. An arrangement that came off in between leaves the swap below to make it.
        if ($subscription->scheduled_swap_arranged_at !== null && $subscription->provider_schedule_id !== null) {
            $subscription->cancelScheduledSwap();
            $this->log->record('billing.scheduled_swap_applied', $owner, ['tier' => $targetTier, 'by' => 'provider'], AuditSource::System);

            return true;
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

        // A change the provider holds until its charge is paid is no change made yet. The row keeps it, and it is tried again
        // once the hold has run out rather than on every run, each of which could raise another charge for it: by then the
        // provider has either made the change, and the swap finds it made, or let it go.
        $held = 'billing:scheduled-swap-held:'.$this->scheduleKey($subscription);

        if (Cache::has($held)) {
            return false;
        }

        try {
            if ($this->actions instanceof AppliesScheduledSwaps) {
                $this->actions->applyScheduledSwap($owner, $targetTier, prorate: $prorate, merchant: $merchant, type: $type, interval: $subscription->scheduled_billing_interval);
            } else {
                $this->actions->swap($owner, $targetTier, prorate: $prorate, merchant: $merchant, type: $type, interval: $subscription->scheduled_billing_interval);
            }
        } catch (SwapAwaitsPayment $awaiting) {
            Cache::put($held, true, $awaiting->expiresAt ?? Carbon::now()->addDay());
            $this->log->record('billing.scheduled_swap_awaits_payment', $owner, ['tier' => $targetTier, 'invoice' => $awaiting->invoice], AuditSource::System);

            return false;
        }

        $subscription->cancelScheduledSwap();

        $this->log->record('billing.scheduled_swap_applied', $owner, [
            'tier' => $targetTier,
        ], AuditSource::System);

        return true;
    }

    /** The row and the moment its change is scheduled for: a change scheduled again is a new one, and nothing held carries over. */
    private function scheduleKey(Subscription $subscription): string
    {
        $key = $subscription->getKey();

        return (is_scalar($key) ? (string) $key : '').':'.$subscription->scheduled_swap_at?->getTimestamp();
    }

    /**
     * Arrange at the provider every scheduled change that comes due within the next days, so a provider that renews on
     * its own renews into the new plan at the boundary instead of raising an invoice for another period of the old one.
     *
     * A change that cannot be arranged, or one scheduled too close to the boundary for a run to reach it before, is
     * applied when it comes due, as before.
     */
    private function arrangeAhead(Carbon $now): void
    {
        $container = Container::getInstance();

        if (! $container->bound(ArrangesAnnualTerms::class)) {
            return;
        }

        $arrangements = $container->make(ArrangesAnnualTerms::class);

        // The provider's own subscriptions, running and not ending: an ending one has no further period a change could
        // begin. A merchant's is among them, since every checkout the package opens puts it on the platform's account.
        $upcoming = Subscription::model()::query()
            ->where('provider', $arrangements->provider())
            ->whereNotNull('provider_id')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNull('ends_at')
            ->whereNotNull('scheduled_tier_key')
            ->whereNull('scheduled_swap_arranged_at')
            ->where('scheduled_swap_at', '>', $now)
            ->where('scheduled_swap_at', '<=', $now->copy()->addDays(self::ARRANGE_AHEAD_DAYS))
            ->orderBy('scheduled_swap_at')
            ->orderBy('id')
            ->get();

        foreach ($upcoming as $subscription) {
            // A change that could not be arranged is tried again the next day, not on every hourly run, so a refusal that
            // lasts, a subscription with a second item for one, is logged once a day and not every hour.
            $attempt = 'billing:scheduled-swap-arrangement:'.$this->scheduleKey($subscription);

            if (Cache::has($attempt)) {
                continue;
            }

            try {
                $arranged = $this->arrange($subscription, $arrangements, $now);
            } catch (Throwable $failure) {
                $arranged = false;

                Log::error('billing: a scheduled plan change could not be arranged at the provider ahead of time, and is applied when it comes due', [
                    'subscription' => $subscription->getKey(),
                    'tier' => $subscription->scheduled_tier_key,
                    'reason' => $failure->getMessage(),
                ]);
            }

            if (! $arranged) {
                Cache::put($attempt, true, $now->copy()->addDay());
            }
        }
    }

    /**
     * The scheduled change as phases at the provider: the target's price for a period and, where a consumer moves to a
     * yearly price, the tier's monthly price after that year, since a consumer is not held to another year by staying
     * silent. False where the change cannot be arranged, and it is then applied when it comes due.
     */
    private function arrange(Subscription $subscription, ArrangesAnnualTerms $arrangements, Carbon $now): bool
    {
        $container = Container::getInstance();
        $catalog = $container->make(AnnualTerms::class)->plansFor($subscription);
        $tierKey = (string) $subscription->scheduled_tier_key;
        // The interval the change moves to, or the one the subscription is billed at. A row that names neither was synced
        // before the interval was recorded, and its swap reads the interval from the provider when it comes due.
        $interval = $subscription->scheduled_billing_interval ?? $subscription->billing_interval;
        $price = $interval instanceof BillingInterval ? $catalog->providerPriceFor($tierKey, $interval) : null;
        $owner = $this->ownerOf($subscription);

        if ($price === null || ! $owner instanceof Model) {
            return false;
        }

        $consumer = ($subscription->customer_type ?? $container->make(CustomerTypes::class)->of($owner)) === CustomerType::Consumer;
        $reference = $arrangements->arrangeChangeAtTermEnd((string) $subscription->provider_id, $price,
            $consumer && $interval === BillingInterval::Year ? $catalog->providerPriceFor($tierKey, BillingInterval::Month) : null);

        if ($reference === null) {
            return false;
        }

        $subscription->forceFill(['provider_schedule_id' => $reference, 'scheduled_swap_arranged_at' => $now])->save();

        return true;
    }

    /**
     * Take a scheduled change that was arranged at the provider off again, before the customer changes or drops it.
     * Without this the provider would still make the change at the boundary.
     */
    public function releaseArranged(Subscription $subscription): void
    {
        $schedule = $subscription->provider_schedule_id;
        $container = Container::getInstance();

        if ($subscription->scheduled_swap_arranged_at === null || $schedule === null || ! $container->bound(ArrangesAnnualTerms::class)) {
            return;
        }

        $container->make(ArrangesAnnualTerms::class)->release($schedule);
        $subscription->forceFill(['provider_schedule_id' => null, 'scheduled_swap_arranged_at' => null])->save();
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
