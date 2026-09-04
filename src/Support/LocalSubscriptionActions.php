<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Drivers\NullSubscriptionActions;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Cancel, resume and swap for a driver whose subscription state lives HERE rather than at a provider.
 *
 * Stripe's adapter tells Stripe and lets the webhook come back. A local engine is the record — there is
 * nobody to tell — so these operate on the row directly. Without this the local drivers fell back to
 * {@see NullSubscriptionActions}, whose methods are empty: canceling did
 * nothing, swapping did nothing, and neither said so. A screen that reports success and changes nothing
 * is the worst of the three possible failures.
 *
 * ## Upgrade now, downgrade at the period end
 *
 * An upgrade takes effect immediately and books the proration credit for the unused remainder of the old
 * plan — the customer asked for more and is charged the difference. A downgrade is SCHEDULED for the
 * period end by default, because they have already paid for the period they are in: switching them down
 * at once would take away access they bought. `billing.subscriptions.downgrade_timing` flips that for an
 * install that would rather refund than wait, and both screens read the same value so they cannot
 * disagree about when a change lands.
 *
 * ## Why a swap is gated and a cancellation is not
 *
 * Swapping reprices and books a proration — a money movement — so it is refused for an ineligible owner
 * even when a caller bypassed the UI. Cancel, resume and cancelNow move no money and stay ungated, which
 * is deliberate: account deletion must always be able to cancel, and an eligibility failure that blocked
 * it would trap a customer in a subscription they are trying to leave.
 */
final readonly class LocalSubscriptionActions implements SubscriptionActions
{
    public function __construct(
        private PlanCatalog $plans,
        private ProrationStrategy $proration,
        private CanTransactMoney $eligibility,
        private Repository $config,
    ) {}

    /**
     * End the subscription at the period it is already paid for, rather than at once.
     *
     * The row stays `active` until then — `ends_at` is what marks the grace period, and the state reads
     * from it. Canceling is not the same as losing access, and a customer who cancels on day two of a
     * month they paid for keeps the month.
     */
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->update([
            'ends_at' => $subscription->current_period_end,
            'scheduled_processing_at' => null,
        ]);
    }

    /**
     * Take back a cancellation that has not landed yet.
     *
     * Only meaningful while the subscription is still inside the period it was canceled in; once it has
     * ended there is nothing to resume, and pretending otherwise would silently restore a subscription
     * nobody is paying for.
     */
    public function resume(Model $billable, ?MerchantScope $merchant = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant);

        if (! $subscription instanceof Subscription || ! $subscription->onGracePeriod()) {
            return;
        }

        $subscription->update([
            'ends_at' => null,
            'scheduled_processing_at' => $subscription->current_period_end,
        ]);
    }

    /**
     * End it now, giving up the remainder of the paid period.
     *
     * Deliberately ungated and deliberately not refunding: this is the path account deletion takes, and it
     * must not be able to fail. Whether the unused remainder is owed back is a separate decision with its
     * own document.
     */
    public function cancelNow(Model $billable, ?MerchantScope $merchant = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->update([
            'status' => SubscriptionState::Ended->value,
            'ends_at' => CarbonImmutable::now(),
            'scheduled_processing_at' => null,
            'scheduled_tier_key' => null,
            'scheduled_swap_at' => null,
        ]);
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null): void
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $plan = $this->plans->planFor($tierKey);

        if (! $plan instanceof Plan) {
            throw new InvalidArgumentException("Tier '{$tierKey}' is not in the catalog.");
        }

        $subscription = $this->subscriptionFor($billable, $merchant);

        if (! $subscription instanceof Subscription) {
            throw new InvalidArgumentException('Cannot swap: the billable has no subscription.');
        }

        if ($this->landsAtPeriodEnd($subscription, $plan)) {
            $subscription->scheduleSwap($tierKey, $subscription->current_period_end ?? CarbonImmutable::now());

            return;
        }

        // Proration first, then the tier: the strategy reads the plan the subscriber is LEAVING to work
        // out what the unused remainder is worth, so repricing the row first would credit them against
        // the plan they are moving to.
        if ($prorate) {
            $this->proration->applySwap($billable, $plan);
        }

        $subscription->update(['tier_key' => $tierKey]);
        $subscription->cancelScheduledSwap();
    }

    /**
     * Whether this change waits for the period end.
     *
     * Only a downgrade waits, and only while the install says so. An upgrade never does — somebody asking
     * for more capacity wants it now, and making them wait for the period end is the one answer nobody
     * asked for.
     */
    private function landsAtPeriodEnd(Subscription $subscription, Plan $plan): bool
    {
        if ($this->config->get('billing.subscriptions.downgrade_timing', 'period_end') !== 'period_end') {
            return false;
        }

        $current = $subscription->tier_key === null ? null : $this->plans->planFor($subscription->tier_key);

        if (! $current instanceof Plan) {
            return false;
        }

        return $plan->amount->minorUnits < $current->amount->minorUnits;
    }

    private function subscriptionFor(Model $billable, ?MerchantScope $merchant): ?Subscription
    {
        return Subscription::query()
            ->forOwner($billable)
            ->ofDefaultType()
            ->forMerchant($merchant)
            ->latest('id')
            ->first();
    }
}
