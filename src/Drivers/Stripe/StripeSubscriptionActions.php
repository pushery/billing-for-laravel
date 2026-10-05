<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Consumer\ProratedCancellation;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\CancellationReason;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;
use RuntimeException;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentMethod;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem;

/**
 * The Stripe implementation of the one provider-mutating subscription seam. It reads the provider
 * subscription reference from the package's local subscription-state row and mutates the Stripe
 * subscription directly; the resulting `customer.subscription.*` webhook syncs the local row back.
 *
 * The swap is the superset closure that keeps upgrades/downgrades in-app: the client submits a tier
 * KEY, never a price, and the price is resolved from the plan catalog (anti-price-injection). A
 * cancel/cancelAt/resume/cancelNow with no live subscription is a safe no-op; a swap without one, to a tier
 * that carries no provider price, or onto a subscription whose tier item cannot be identified, is
 * rejected rather than silently doing nothing — or, worse, repricing the wrong item.
 */
final readonly class StripeSubscriptionActions implements AppliesScheduledSwaps, SubscriptionActions
{
    /**
     * The payment methods, of those this driver collects, under which Stripe can hold a swap until it is paid.
     *
     * Stripe offers pending updates for a card and for Link, and not for a bank debit such as SEPA Direct Debit.
     */
    private const array HOLDABLE_METHODS = ['card', 'link'];

    public function __construct(
        private StripeClient $stripe,
        private MerchantCatalog $catalogs,
        private StripeSubscriptionItems $items,
        private CanTransactMoney $eligibility,
    ) {}

    public function cancel(Model $billable, ?CancellationSurvey $survey = null, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $subscription?->provider_id;

        // An earlier end stays where it is. Canceling at the period end on top of a cancellation to a date would
        // move Stripe's `cancel_at` out to the period end, past a rest of the period that may have been refunded.
        if (! $subscription instanceof Subscription || $reference === null || $subscription->endInsideItsPeriod() instanceof Carbon) {
            return;
        }

        $payload = ['cancel_at_period_end' => true];

        // Surface the owner's reason on Stripe's own cancellation_details so it shows in the provider's churn
        // view next to the local record. Purely additive — a null survey just omits it, and it never affects
        // whether the cancellation goes through.
        if ($survey instanceof CancellationSurvey) {
            $details = ['feedback' => $this->stripeFeedback($survey->reason)];

            if ($survey->hasDetail()) {
                $details['comment'] = (string) $survey->detail;
            }

            $payload['cancellation_details'] = $details;
        }

        $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->update($reference, $payload));
    }

    /**
     * Translate the package's provider-neutral cancellation reason into Stripe's fixed
     * cancellation-feedback vocabulary. The enum itself knows no provider (like SubscriptionState); this
     * Stripe-specific mapping lives with the Stripe driver. NoLongerNeeded and NotUsingEnough both collapse
     * onto Stripe's coarser `unused` — the precise reason is kept on the local survey record regardless.
     */
    private function stripeFeedback(CancellationReason $reason): string
    {
        return match ($reason) {
            CancellationReason::TooExpensive => 'too_expensive',
            CancellationReason::MissingFeatures => 'missing_features',
            CancellationReason::NotUsingEnough, CancellationReason::NoLongerNeeded => 'unused',
            CancellationReason::SwitchedProvider => 'switched_service',
            CancellationReason::TechnicalIssues => 'low_quality',
            CancellationReason::Other => 'other',
        };
    }

    /**
     * Take back a cancellation at the period end.
     *
     * A cancellation to a moment inside the period is refused rather than taken back: the rest of the period
     * after that moment may have been refunded, and clearing `cancel_at` would hand it back unpaid.
     */
    public function resume(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $early = $subscription?->endInsideItsPeriod();

        if ($early instanceof Carbon) {
            throw EndInsidePeriodIsFinal::forResume($early);
        }

        $reference = $subscription?->provider_id;

        if ($reference !== null) {
            $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->update($reference, ['cancel_at_period_end' => false]));
        }
    }

    /**
     * End the subscription at a moment inside the period it is in, through Stripe's own `cancel_at`.
     *
     * Without proration. Stripe would otherwise book the unused rest as a credit on the customer's balance,
     * where nothing after the end ever spends it. The rest goes back to the payment instead, through the rails,
     * which is what {@see ProratedCancellation} does after this.
     *
     * The local row learns the new state from the `customer.subscription.updated` webhook, like every other
     * mutation here.
     */
    public function cancelAt(Model $billable, CarbonInterface $endsAt, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $subscription?->provider_id;

        if (! $subscription instanceof Subscription || $reference === null) {
            return;
        }

        $subscription->assertCanEndAt($endsAt);

        $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->update($reference, [
            'cancel_at' => $endsAt->getTimestamp(),
            'proration_behavior' => 'none',
        ]));
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $reference = $this->subscriptionFor($billable, $merchant, $type)?->provider_id;

        if ($reference !== null) {
            $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->cancel($reference));
        }
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        // Defense in depth: a swap reprices the subscription and books a proration — a money movement — so
        // refuse it for an ineligible owner even if a caller bypassed the UI eligibility guard (mirrors
        // StripeCheckout / StripeOneTimeCharge). cancel/resume/cancelNow move no money and stay ungated so
        // account deletion can always cancel.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->reprice($billable, $tierKey, $prorate, $merchant, $type);
    }

    /** The swap a scheduled change comes to, without asking the gate again: it was asked when the change was made. */
    public function applyScheduledSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $this->reprice($billable, $tierKey, $prorate, $merchant, $type);
    }

    private function reprice(Model $billable, string $tierKey, bool $prorate, ?MerchantScope $merchant, ?string $type): void
    {
        // The price comes from the MERCHANT's catalog, so a marketplace swap reprices against the creator's
        // own tier — never the platform's, and never a price the client named. A null merchant reads the
        // platform catalog exactly as before.
        $price = $this->catalogs->planCatalog($merchant)->providerPriceFor($tierKey);

        if ($price === null) {
            throw new InvalidArgumentException("Tier '{$tierKey}' has no provider price to swap to.");
        }

        $reference = $this->subscriptionFor($billable, $merchant, $type)?->provider_id;

        if ($reference === null) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($reference, [
                // The two places Stripe takes the method it charges from, read in the same call.
                'expand' => ['default_payment_method', 'customer.invoice_settings.default_payment_method'],
            ]);
        } catch (RateLimitException $e) {
            // A 429 is TRANSIENT and only lands here because the SDK makes RateLimitException a
            // subclass of InvalidRequestException. Swallowing it files "try again" as "never".
            throw $e;
        } catch (InvalidRequestException) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        $base = $this->items->base($subscription, $merchant);

        if (! $base instanceof SubscriptionItem) {
            throw new RuntimeException("Cannot swap: the tier item on Stripe subscription {$reference} cannot be identified.");
        }

        // Only the base item is repriced. Any metered component or app-owned item on the subscription is
        // absent from the payload, which Stripe leaves exactly as it is — repricing it would destroy it.
        $this->stripe->subscriptions->update($reference, [
            'items' => [['id' => $base->id, 'price' => $price]],
            'proration_behavior' => $prorate ? 'create_prorations' : 'none',
            ...$this->paymentBehaviorFor($subscription),
        ]);
    }

    /**
     * Hold a swap until its charge is paid, wherever Stripe can.
     *
     * A swap that changes the billing interval, ends a trial or moves a free subscription to a paid price is
     * charged at once. Under Stripe's default, `allow_incomplete`, the new price applies whether that charge
     * succeeds or not, so a declined card leaves the subscription `past_due` on a price nobody paid for.
     * `pending_if_incomplete` applies the change only once the invoice is paid, and until then the subscription
     * stays on its price and in its state. A swap that charges nothing applies at once either way.
     *
     * Stripe holds an update only under automatic collection and only for some payment methods, see
     * {@see self::HOLDABLE_METHODS}. Any other method, or one this cannot tell, keeps Stripe's default.
     *
     * @return array{payment_behavior?: 'pending_if_incomplete'}
     */
    private function paymentBehaviorFor(StripeSubscription $subscription): array
    {
        if (($subscription->collection_method ?? null) !== 'charge_automatically') {
            return [];
        }

        return in_array($this->chargedMethodType($subscription), self::HOLDABLE_METHODS, true)
            ? ['payment_behavior' => 'pending_if_incomplete']
            : [];
    }

    /**
     * The type of the payment method Stripe charges for the subscription, or null when it cannot be told.
     *
     * Stripe's order: the subscription's own default, then a legacy source on the subscription, then the
     * customer's invoice default. A legacy source has no type this reads, so it answers null.
     *
     * Every read goes through `??`: a Stripe object logs a notice for a key its response does not carry, and
     * `??` asks `__isset` first.
     */
    private function chargedMethodType(StripeSubscription $subscription): ?string
    {
        $own = $subscription->default_payment_method ?? null;

        if ($own instanceof PaymentMethod) {
            return $own->type;
        }

        if ($own !== null || ($subscription->default_source ?? null) !== null) {
            return null;
        }

        $customer = $subscription->customer ?? null;
        $default = $customer instanceof Customer ? ($customer->invoice_settings->default_payment_method ?? null) : null;

        return $default instanceof PaymentMethod ? $default->type : null;
    }

    /**
     * Run a subscription mutation, swallowing the error Stripe raises when the subscription is already
     * canceled or gone. cancel/resume/cancelNow must be a safe no-op on a dead subscription — account
     * deletion calls cancelNow and must never be blocked by an already-canceled subscription.
     *
     * Only those. Every refused request is an `InvalidRequestException`, and swallowing all of them let a
     * refusal of the change itself pass as done: `cancelAt()` returned, and the prorated cancellation after it
     * refunded the rest of a subscription that went on billing. A 404 is a subscription that is gone. Any other
     * refusal is asked of the subscription, and only one that has ended is a no-op.
     *
     * @param  callable(): mixed  $call
     */
    private function ignoringDeadSubscription(string $reference, callable $call): void
    {
        try {
            $call();
        } catch (RateLimitException $e) {
            // A 429 is TRANSIENT and only lands here because the SDK makes RateLimitException a
            // subclass of InvalidRequestException. Swallowing it files "try again" as "never".
            throw $e;
        } catch (InvalidRequestException $refusal) {
            if ($refusal->getHttpStatus() !== 404 && ! $this->hasEnded($reference)) {
                throw $refusal;
            }
        }
    }

    /** Whether Stripe holds the subscription as ended, the one reason a refusal of a change to it is no news. */
    private function hasEnded(string $reference): bool
    {
        return in_array($this->stripe->subscriptions->retrieve($reference)->status, ['canceled', 'incomplete_expired'], true);
    }

    /**
     * The billable's local subscription row, whose `provider_id` is the Stripe subscription, or null.
     *
     * Scoped to the merchant so a marketplace mutation addresses exactly the (fan, creator) subscription; a
     * null merchant reproduces the single-seller selection exactly (`merchant_uid = 'platform'`). Scoped to
     * the contract type as well, so a sponsorship and a subscription at the same creator are told apart; a
     * null type is the default contract.
     */
    private function subscriptionFor(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($billable)
            ->forMerchant($merchant)
            ->ofType($type)
            ->latest('id')
            ->first();
    }
}
