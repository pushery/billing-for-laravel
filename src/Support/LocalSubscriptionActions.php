<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Drivers\NullSubscriptionActions;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
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
     * End the subscription at the end of the period it is in, rather than at once.
     *
     * The row stays `active` until then — `ends_at` is what marks the grace period, and the state reads
     * from it. Canceling is not the same as losing access, and a customer who cancels on day two of a
     * month keeps the month.
     *
     * The schedule stays where it is. The engine collects a period at its end, so the cycle due then is the
     * one that bills this period, and the end makes it the last: it closes the subscription instead of
     * opening the next period. Clearing the schedule here meant the period was never billed and the row
     * never ended.
     *
     * A subscription already canceled to a moment inside its period keeps that moment. Moving it out to
     * the period end would give back time a prorated cancellation may have refunded.
     */
    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription || $subscription->endInsideItsPeriod() instanceof Carbon) {
            return;
        }

        $subscription->update(['ends_at' => $subscription->current_period_end]);
    }

    /**
     * Take back a cancellation that has not landed yet.
     *
     * Only meaningful while the subscription is still inside the period it was canceled in; once it has
     * ended there is nothing to resume, and pretending otherwise would silently restore a subscription
     * nobody is paying for.
     *
     * A cancellation to a moment inside the period is refused rather than taken back. It is final on every
     * driver, because on a driver that collects in advance the rest of the period may have been refunded,
     * and one rule for all of them is the one a screen can rely on.
     *
     * @throws InvalidArgumentException when the subscription was canceled to a moment inside its period
     */
    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription || ! $subscription->onGracePeriod()) {
            return;
        }

        $early = $subscription->endInsideItsPeriod();

        if ($early instanceof Carbon) {
            throw EndInsidePeriodIsFinal::forResume($early);
        }

        $subscription->update([
            'ends_at' => null,
            'scheduled_processing_at' => $subscription->current_period_end,
        ]);
    }

    /**
     * End the subscription at a moment inside the period it is in.
     *
     * The same shape as `cancel()` with an earlier date: the row stays `active` until then and `ends_at`
     * marks the grace period. The last cycle moves to that moment, where it bills the days up to it and
     * closes the subscription.
     *
     * @throws InvalidArgumentException when the moment has passed or lies after the period end
     */
    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->assertCanEndAt($endsAt);

        $subscription->update([
            'ends_at' => CarbonImmutable::instance($endsAt),
            'scheduled_processing_at' => CarbonImmutable::instance($endsAt),
        ]);
    }

    /**
     * End it now, giving up the remainder of the paid period.
     *
     * Deliberately ungated and deliberately not refunding: this is the path account deletion takes, and it
     * must not be able to fail. Whether the unused remainder is owed back is a separate decision with its
     * own document.
     */
    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);

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

    /**
     * Move the contract to another tier: now for an upgrade, at the period end for a downgrade.
     *
     * A contract of a type other than the default is swapped without proration or not at all. The proration
     * strategy prices the owner, not a row: it reads the tier and the period the owner resolves to, which
     * are the default contract's. Prorating a sponsorship against them would credit the unused remainder of
     * a different contract, so that combination is refused before anything changes.
     */
    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $plan = $this->plans->planFor($tierKey);

        if (! $plan instanceof Plan) {
            throw new InvalidArgumentException("Tier '{$tierKey}' is not in the catalog.");
        }

        $subscription = $this->subscriptionFor($billable, $merchant, $type);

        if (! $subscription instanceof Subscription) {
            throw new InvalidArgumentException('Cannot swap: the billable has no subscription.');
        }

        if ($prorate && ($type ?? Subscription::TYPE_DEFAULT) !== Subscription::TYPE_DEFAULT) {
            throw new InvalidArgumentException(
                "Cannot prorate a swap of the [{$type}] contract: the local engine prices proration against the "
                .'default contract. Swap it with prorate: false.'
            );
        }

        if (! $this->isDueSchedule($subscription, $tierKey) && $this->landsAtPeriodEnd($subscription, $plan)) {
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
     * Whether this swap IS the row's own schedule coming due: the tier it scheduled, at or after its moment.
     *
     * The scheduled-swap runner applies a due downgrade through this same method. Without this answer the
     * downgrade was a downgrade again, so it was scheduled again for the end of the period that had just
     * begun, and the runner then cleared that schedule too: the tier never moved, and the audit ledger said
     * it had.
     */
    private function isDueSchedule(Subscription $subscription, string $tierKey): bool
    {
        return $subscription->scheduled_tier_key === $tierKey
            && $subscription->scheduled_swap_at !== null
            && ! $subscription->scheduled_swap_at->isFuture();
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

    private function subscriptionFor(Model $billable, ?MerchantScope $merchant, ?string $type = null): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($billable)
            ->ofType($type)
            ->forMerchant($merchant)
            ->latest('id')
            ->first();
    }
}
