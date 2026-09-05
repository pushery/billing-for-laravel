<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Discounts\CouponRedeemer;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Exceptions\CouponUnavailable;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\SubscriptionIntent;
use Pushery\Billing\Support\TierInterval;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Turn the mandate a customer just granted into the subscription they asked for.
 *
 * ## Why this is where the subscription is born
 *
 * Under a provider with no synchronous setup call, the mandate arrives over the webhook rather than on the
 * return redirect — the browser may never come back, the webhook fires either way. So this is the first
 * moment at which the package knows the customer can be charged, and until then there is nothing a
 * subscription could honestly say.
 *
 * ## It matches on the PAYMENT, and that is the whole safety property
 *
 * Establishing a mandate is also what happens when somebody merely adds a second payment method. Matched
 * on the customer, that would consume a pending intent and hand them a subscription they never asked for.
 * The payment reference is what makes an answer belong to a question — a mandate granted by some other
 * payment finds no intent and does nothing at all, which is exactly right.
 *
 * ## Idempotent, because the delivery repeats
 *
 * The claim and the write happen in one transaction, and the claim is a conditional UPDATE rather than a
 * read followed by a write: two deliveries arriving together would otherwise both see an unclaimed intent
 * and both create a subscription, and the customer would be billed twice for one sale. The row the second
 * delivery updates is already claimed, so it changes nothing and returns.
 */
final readonly class StartSubscriptionOnMandate
{
    public function __construct(private TierInterval $intervals, private CouponRedeemer $coupons) {}

    public function __invoke(MandateEstablished $event): void
    {
        if ($event->paymentReference === null) {
            // A provider that grants mandates without a payment behind them. Nothing to match on, and
            // guessing from the customer is the failure this effect is written to avoid.
            return;
        }

        DB::transaction(function () use ($event): void {
            // The conditional update IS the claim. Reading first and writing after would let two
            // simultaneous deliveries both pass the read.
            $claimed = SubscriptionIntent::query()
                ->where('payment_reference', $event->paymentReference)
                ->where('provider', $event->provider)
                ->whereNull('claimed_at')
                ->update(['claimed_at' => CarbonImmutable::now()]);

            if ($claimed === 0) {
                return; // no such intent, or somebody already acted on it
            }

            // `firstOrFail`, not `first` plus a null check. The row was claimed by the UPDATE above, inside
            // this transaction, so it is there — a null would mean something impossible happened, and
            // returning quietly on it would leave a customer who has PAID with no subscription and nothing
            // anywhere saying so. Failing puts it where a failed job is looked at.
            $intent = SubscriptionIntent::query()
                ->where('payment_reference', $event->paymentReference)
                ->where('provider', $event->provider)
                ->firstOrFail();

            $this->write($intent);
        });
    }

    private function write(SubscriptionIntent $intent): void
    {
        $now = CarbonImmutable::now();
        $trialEndsAt = $intent->trial_ends_at === null
            ? null
            : CarbonImmutable::createFromInterface($intent->trial_ends_at);

        // A trial pushes the FIRST cycle out to its end; without one the period starts now and the engine
        // charges it on its own schedule. Charging here would take money on a webhook, which is the one
        // place a retry is guaranteed and an amount is not.
        // The SAME reader the engine advances by, not a second answer to one question. Hardcoded to a
        // month here, an ANNUAL subscriber's very first period is thirty days long — so the sweep collects
        // the annual price again a month after they signed up, before the engine's own reading gets a turn.
        $periodEnd = $trialEndsAt ?? CarbonImmutable::createFromInterface(
            $this->intervals->for($intent->tier_key)->advance($now)
        );

        // AN INTENT NEVER OVERWRITES A LIVE SUBSCRIPTION, and the check has to be here rather than at the
        // redirect. `LocalSubscriptionStarter::alreadySubscribed()` runs when the customer presses
        // Subscribe — minutes, or with a bank transfer DAYS, before this. Intents carry no expiry and are
        // unique only on the payment reference, so an owner can hold several: press Subscribe on `basic`,
        // wander off, come back and buy `pro`, and the abandoned `basic` payment settling later arrives
        // here with a perfectly valid claim.
        //
        // With `updateOrCreate` alone that claim WON the row: tier replaced, period restarted, a paid
        // subscriber moved to the tier they abandoned, dunning state wiped — decided by webhook delivery
        // order, with no failure, no log line and nothing to diagnose it from. The plain `create()` this
        // replaced at least died loudly on the unique constraint.
        //
        // So the slot is reused only when the row standing in it is one the starter itself would let a
        // customer subscribe over. That list lives there; it is read here through the model so the two
        // cannot drift apart.
        $existing = Subscription::query()
            ->where('owner_type', $intent->owner_type)
            ->where('owner_id', $intent->owner_id)
            ->where('type', Subscription::TYPE_DEFAULT)
            ->where('merchant_uid', MerchantScope::platform()->uid())
            ->first();

        if ($existing instanceof Subscription && ! $existing->isReplaceableByANewSubscription()) {
            // Claimed and NOT retried, deliberately. Throwing would roll the claim back with the
            // transaction and the job would return to exactly this state on every attempt until the queue
            // gave up. The customer keeps the subscription they actually have, their mandate is on file
            // (the sibling effect stored it), and the stale intent is spent — what is left is a support
            // question about one verification payment, which is the smallest of the outcomes available.
            Log::warning('billing: a subscription intent settled for an owner who already has a live subscription', [
                'intent' => $intent->getKey(),
                'payment_reference' => $intent->payment_reference,
                'intent_tier' => $intent->tier_key,
                'subscription' => $existing->getKey(),
                'subscription_status' => $existing->status,
            ]);

            return;
        }

        // updateOrCreate on the UNIQUE KEY, not create. An ENDED row still occupies that slot: the starter
        // deliberately lets a customer who once canceled subscribe again, because refusing would leave them
        // permanently unable to return. A plain insert then dies on the constraint — and dies HERE, after
        // the customer has completed a real payment. They would have paid, hold no subscription, and leave
        // nothing behind but a dead-lettered job.
        //
        // Reusing the row rather than inserting a second one is also the right answer on its own terms: the
        // id survives, and so does everything joined to it.
        $subscription = Subscription::query()->updateOrCreate(
            [
                'owner_type' => $intent->owner_type,
                'owner_id' => $intent->owner_id,
                'type' => Subscription::TYPE_DEFAULT,
                'merchant_uid' => MerchantScope::platform()->uid(),
            ],
            [
                'provider' => $intent->provider,
                'status' => ($trialEndsAt instanceof CarbonImmutable
                    ? SubscriptionState::Trialing
                    : SubscriptionState::Active)->value,
                'tier_key' => $intent->tier_key,
                // PRESERVED, never nulled — the same shape `SyncPlanFromSubscription` uses for this exact
                // column. `LocalSubscriptionStarter` decides "has this owner had a trial" from it, so a
                // plain write destroys the evidence the guard reads: the second, trial-LESS subscribe
                // settles here, sets this to null, and the third one hands out a fresh free trial. One
                // trial per two cycles, resettable forever at one verification payment a time — the defect
                // the guard exists to close, one paid round trip deeper and invisible in the data.
                'trial_ends_at' => $trialEndsAt ?? $existing?->trial_ends_at,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'scheduled_processing_at' => $periodEnd,
                // The previous life has to be cleared, every field of it. A returning customer whose
                // `ends_at` survived would be active and ending at once — and the presenter reads that as a
                // grace period, so they would be shown a cancellation they never made. The dunning fields
                // are the same argument: arrears from a subscription that is over are not this one's.
                'ends_at' => null,
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
                'terminated_at' => null,
                'scheduled_tier_key' => null,
                'scheduled_swap_at' => null,
            ],
        );

        $this->redeemCarriedCoupon($intent, $subscription);
    }

    /**
     * Spend the coupon the customer typed before the redirect, now that there is a subscription to spend it
     * on.
     *
     * ## This is the moment, and the two obvious alternatives are both worse
     *
     * Redeeming at the redirect would take the owner's single allowed redemption -- and one of the coupon's
     * global slots -- for a checkout they may never finish, with no way to give either back. Redeeming
     * later, on the first cycle, would mean the discount depends on a sweep having run, and a coupon that
     * silently applies a month after the customer used it is one they will have written in about already.
     *
     * ## Re-checked here rather than trusted from the intent
     *
     * Minutes pass between the redirect and this webhook -- with a bank transfer, days. The coupon may have
     * been deactivated, expired, or spent down to its cap in between, and the redeemer is the only place
     * those can be answered without a race. So the code is carried and the ENTITLEMENT is re-derived.
     *
     * ## And it never fails the delivery
     *
     * The customer has paid by the time this runs. A throw would roll the whole transaction back -- claim
     * included -- and the job would return here on every retry until the queue gave up, leaving somebody
     * who paid with no subscription. A missed discount is a support conversation; that is not.
     */
    private function redeemCarriedCoupon(SubscriptionIntent $intent, Subscription $subscription): void
    {
        $code = $intent->coupon_code;

        if ($code === null || $code === '') {
            return;
        }

        $coupon = Coupon::query()->where('code', $code)->first();
        $owner = $this->ownerOf($intent);

        if (! $coupon instanceof Coupon || ! $owner instanceof Model) {
            return;
        }

        try {
            $this->coupons->redeem($coupon, $owner, $subscription->id);
        } catch (CouponUnavailable $unavailable) {
            Log::info('billing: a carried coupon could not be redeemed when its mandate settled', [
                'intent' => $intent->getKey(),
                'coupon' => $code,
                'subscription' => $subscription->getKey(),
                'reason' => $unavailable->getMessage(),
            ]);
        }
    }

    /**
     * The owner this intent was raised for, resolved back to the consumer's own model.
     *
     * Through the morph map, which is the same shape `LocalBillingEngine::ownerFor()` uses -- and it has to
     * be, because the redemption written here is looked up by `CycleCouponApplier` with the owner THAT
     * loads. A redemption keyed on anything else is one the cycle never finds, and the customer is billed
     * in full while a redemption row sits in the table saying otherwise.
     *
     * Null when the owner cannot be resolved -- deleted between the redirect and the settlement, or a morph
     * alias the application has since dropped. An ordinary race rather than a defect, and the subscription
     * is already written by the time it is asked.
     */
    private function ownerOf(SubscriptionIntent $intent): ?Model
    {
        // No `is_string()` guard, unlike the engine's twin: the intent's column is typed, so the coalesce
        // cannot produce anything else here, and a check the type system already makes is a branch no run
        // can enter -- which under a 100% floor reads as a missing test rather than as an impossible case.
        $class = Relation::getMorphedModel($intent->owner_type) ?? $intent->owner_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($intent->owner_id);

        return $owner instanceof Model ? $owner : null;
    }
}
