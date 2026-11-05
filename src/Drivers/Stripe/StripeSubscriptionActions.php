<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pushery\Billing\Consumer\ProratedCancellation;
use Pushery\Billing\Contracts\AppliesScheduledSwaps;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\PausesSubscriptions;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\PreviewsSwaps;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CancellationReason;
use Pushery\Billing\Events\SubscriptionPaused;
use Pushery\Billing\Events\SubscriptionUnpaused;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
use Pushery\Billing\Exceptions\SwapAwaitsPayment;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Invoice;
use Stripe\PaymentMethod;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem;
use Throwable;

/**
 * The Stripe implementation of the one provider-mutating subscription seam. It reads the provider
 * subscription reference from the package's local subscription-state row and mutates the Stripe
 * subscription directly; the resulting `customer.subscription.*` webhook syncs the local row back.
 *
 * The swap is the superset closure that keeps upgrades/downgrades in-app: the client submits a tier
 * key, never a price, and the price is resolved from the plan catalog (anti-price-injection). A
 * cancel/cancelAt/resume/cancelNow with no live subscription is a safe no-op; a swap without one, to a tier
 * that carries no provider price, or onto a subscription whose tier item cannot be identified, is
 * rejected rather than silently doing nothing — or, worse, repricing the wrong item.
 */
final readonly class StripeSubscriptionActions implements AppliesScheduledSwaps, PausesSubscriptions, PreviewsSwaps, SubscriptionActions
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

        $this->letGoOfTheSchedule($subscription);

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

        if ($subscription instanceof Subscription && $reference !== null) {
            $this->letGoOfTheSchedule($subscription);
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
        $this->letGoOfTheSchedule($subscription);

        $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->update($reference, [
            'cancel_at' => $endsAt->getTimestamp(),
            'proration_behavior' => 'none',
        ]));
    }

    /**
     * Pause collection at Stripe, which raises no invoice while it rests and keeps its own periods.
     *
     * `void` rather than keeping drafts: an invoice kept for later would collect the days the customer could not use
     * the moment the pause ends. The local row learns the paused state from the `customer.subscription.updated`
     * webhook, like every other mutation here; the event goes out once Stripe accepted the pause.
     *
     * @throws InvalidArgumentException when the subscription is not active, or is ending
     */
    public function pause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $subscription?->provider_id;

        if (! $subscription instanceof Subscription || $reference === null) {
            return;
        }

        if (! $subscription->active() || $subscription->ends_at !== null) {
            throw new InvalidArgumentException(
                "Cannot pause the subscription ({$subscription->status}): only an active subscription that is not ending can rest."
            );
        }

        $this->letGoOfTheSchedule($subscription);
        $this->stripe->subscriptions->update($reference, ['pause_collection' => ['behavior' => 'void']]);

        Event::dispatch(new SubscriptionPaused($billable));
    }

    /** Take the pause off at Stripe, which collects again from its next invoice. */
    public function unpause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $subscription?->provider_id;

        if (! $subscription instanceof Subscription || $reference === null || ! $subscription->paused()) {
            return;
        }

        // An empty `pause_collection` is how the Stripe API clears a pause, and the request StripeSubscriptionActionsTest
        // asserts. The SDK's generated shape types the field as an object or null and cannot express the empty string.
        // @phpstan-ignore argument.type (the SDK's generated shape cannot express the empty string that clears a field)
        $this->stripe->subscriptions->update($reference, ['pause_collection' => '']);

        Event::dispatch(new SubscriptionUnpaused($billable));
    }

    public function cancelNow(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void
    {
        $subscription = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $subscription?->provider_id;

        if (! $subscription instanceof Subscription || $reference === null) {
            return;
        }

        // Taken off first, and never in the way: deleting an account calls this, and a schedule that cannot be
        // released this once must not keep a subscription running. Stripe ends the schedule with its subscription.
        try {
            $this->letGoOfTheSchedule($subscription);
        } catch (Throwable $failure) {
            // @rate-limit-deliberate: a 429 here costs only the release of the schedule, and Stripe ends a schedule with the subscription canceled next.
            Log::warning('billing: the schedule of a subscription could not be released before it was canceled', [
                'subscription' => $reference,
                'reason' => $failure->getMessage(),
            ]);
        }

        $this->ignoringDeadSubscription($reference, fn () => $this->stripe->subscriptions->cancel($reference));
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): void
    {
        // Defense in depth: a swap reprices the subscription and books a proration — a money movement — so
        // refuse it for an ineligible owner even if a caller bypassed the UI eligibility guard (mirrors
        // StripeCheckout / StripeOneTimeCharge). cancel/resume/cancelNow move no money and stay ungated so
        // account deletion can always cancel.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->refuseAHeldChange($this->reprice($billable, $tierKey, $prorate, $merchant, $type, $interval));
    }

    /** The swap a scheduled change comes to, without asking the gate again: it was asked when the change was made. */
    public function applyScheduledSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): void
    {
        $this->refuseAHeldChange($this->reprice($billable, $tierKey, $prorate, $merchant, $type, $interval));
    }

    /**
     * Say so where Stripe holds the change until its charge is paid: the subscription is still on its price, and a
     * return would read as done. The screen that asked can send the customer to pay, and `billing:run` keeps the change
     * it scheduled until the hold has run out.
     *
     * @throws SwapAwaitsPayment
     */
    private function refuseAHeldChange(StripeSubscription $updated): void
    {
        $pending = $updated->pending_update ?? null;

        if ($pending === null) {
            return;
        }

        $invoice = $updated->latest_invoice ?? null;
        $expires = $pending->expires_at ?? null;

        throw SwapAwaitsPayment::held(
            $updated->id,
            $invoice instanceof Invoice ? $invoice->id : (is_string($invoice) ? $invoice : null),
            $invoice instanceof Invoice ? ($invoice->hosted_invoice_url ?? null) : null,
            is_int($expires) ? Carbon::createFromTimestampUTC($expires) : null,
        );
    }

    /**
     * What a swap would cost, priced by Stripe for exactly the change {@see self::swap()} would make.
     *
     * The subscription, the item and the price come from the same lookup the swap uses, so the preview cannot name
     * another interval or another item than the swap that follows it. Nothing is written: a schedule that holds the
     * subscription stays on it, and the eligibility gate is not asked, because no money moves.
     */
    public function previewSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): ?Money
    {
        try {
            $target = $this->swapTarget($billable, $tierKey, $merchant, $type, $interval);
            $customer = $target['subscription']->customer ?? null;
            $customerId = $customer instanceof Customer ? $customer->id : $customer;

            $preview = $this->stripe->invoices->createPreview([
                ...(is_string($customerId) ? ['customer' => $customerId] : []),
                'subscription' => $target['reference'],
                'subscription_details' => [
                    'items' => [['id' => $target['base']->id, 'price' => $target['price']]],
                    'proration_behavior' => $prorate ? 'create_prorations' : 'none',
                ],
            ]);
        } catch (InvalidArgumentException|RuntimeException) {
            // The swap would be refused, so there is no cost to show; the swap itself says why.
            return null;
        } catch (ApiErrorException $failure) {
            // @rate-limit-deliberate: a 429 lands here as well, and answering it with no figure is right at this
            // site. The preview writes nothing and marks nothing done, the screen asking for it shows a figure and
            // nothing else, and asking again is the customer's next look at it. A failure is logged rather than
            // silent, because a rotated key does not clear by itself.
            Log::warning('Could not preview a subscription swap; showing no estimate.', [
                'exception' => $failure::class,
                'message' => $failure->getMessage(),
            ]);

            return null;
        }

        return StripeAmount::toMoney($preview->amount_due, strtoupper($preview->currency));
    }

    /** The subscription as Stripe returns it after the change, with the invoice of a change it holds. */
    private function reprice(Model $billable, string $tierKey, bool $prorate, ?MerchantScope $merchant, ?string $type, ?BillingInterval $interval = null): StripeSubscription
    {
        $target = $this->swapTarget($billable, $tierKey, $merchant, $type, $interval);

        // A schedule would undo the new price at its next phase, so it comes off first. Where the subscription is still
        // a consumer's annual one afterwards, the next event about it arranges its year again, at the new tier.
        $this->letGoOfTheSchedule($target['row']);

        $behavior = $this->paymentBehaviorFor($target['subscription']);

        // Only the base item is repriced. Any metered component or app-owned item on the subscription is
        // absent from the payload, which Stripe leaves exactly as it is — repricing it would destroy it.
        // Where Stripe may hold the change, the invoice it holds it for comes back expanded, with the page where it is paid.
        return $this->stripe->subscriptions->update($target['reference'], [
            'items' => [['id' => $target['base']->id, 'price' => $target['price']]],
            'proration_behavior' => $prorate ? 'create_prorations' : 'none',
            ...$behavior,
            ...($behavior === [] ? [] : ['expand' => ['latest_invoice']]),
        ]);
    }

    /**
     * The subscription a swap changes, its tier item and the price that item moves to.
     *
     * @return array{row: Subscription, reference: string, subscription: StripeSubscription, base: SubscriptionItem, price: string}
     *
     * @throws InvalidArgumentException when the tier has no price at the interval or there is no live subscription
     * @throws RuntimeException when the tier item on the subscription cannot be identified
     */
    private function swapTarget(Model $billable, string $tierKey, ?MerchantScope $merchant, ?string $type, ?BillingInterval $interval): array
    {
        // The price comes from the merchant's catalog, so a marketplace swap reprices against the creator's
        // own tier — never the platform's, and never a price the client named. A null merchant reads the
        // platform catalog exactly as before.
        $catalog = $this->catalogs->planCatalog($merchant);
        $price = $catalog->providerPriceFor($tierKey, $interval);

        if ($price === null) {
            throw new InvalidArgumentException($interval instanceof BillingInterval
                ? "Tier '{$tierKey}' has no provider price to swap to at the interval {$interval->value}."
                : "Tier '{$tierKey}' has no provider price to swap to.");
        }

        $row = $this->subscriptionFor($billable, $merchant, $type);
        $reference = $row?->provider_id;

        if (! $row instanceof Subscription || $reference === null) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($reference, [
                // The two places Stripe takes the method it charges from, read in the same call.
                'expand' => ['default_payment_method', 'customer.invoice_settings.default_payment_method'],
            ]);
        } catch (RateLimitException $e) {
            // A 429 is transient and only lands here because the SDK makes RateLimitException a
            // subclass of InvalidRequestException. Swallowing it files "try again" as "never".
            throw $e;
        } catch (InvalidRequestException) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        $base = $this->items->base($subscription, $merchant);

        if (! $base instanceof SubscriptionItem) {
            throw new RuntimeException("Cannot swap: the tier item on Stripe subscription {$reference} cannot be identified.");
        }

        // A swap that names no interval keeps the one the subscription is billed at, where the new tier is sold at
        // it: a yearly subscriber who changes the tier stays yearly instead of being moved to the monthly price.
        if (! $interval instanceof BillingInterval) {
            $price = $this->priceAtTheCurrentInterval($catalog, $tierKey, $base) ?? $price;
        }

        return ['row' => $row, 'reference' => $reference, 'subscription' => $subscription, 'base' => $base, 'price' => $price];
    }

    /**
     * The new tier's price at the interval the base item is billed at now, or null where the tier is not sold at it or
     * the item's price recurs at a count of intervals other than one.
     */
    private function priceAtTheCurrentInterval(PlanCatalog $catalog, string $tierKey, SubscriptionItem $base): ?string
    {
        // Read off the item as an array: an item Stripe sent without its price must not end the swap.
        $price = $base->toArray()['price'] ?? null;
        $recurring = is_array($price) ? ($price['recurring'] ?? null) : null;
        $named = is_array($recurring) && ($recurring['interval_count'] ?? 1) === 1 ? ($recurring['interval'] ?? null) : null;
        $current = is_string($named) ? BillingInterval::tryFrom($named) : null;

        return $current instanceof BillingInterval && in_array($current, $catalog->intervalsFor($tierKey), true)
            ? $catalog->providerPriceFor($tierKey, $current)
            : null;
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
            // A 429 is transient and only lands here because the SDK makes RateLimitException a
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
     * Take the schedule off the subscription before a change to it, where one holds it.
     *
     * Stripe refuses a change to the cancellation of a subscription a schedule holds, and a schedule would undo any other
     * change at its next phase. Where the arrangement still applies afterwards, the next event about the subscription
     * arranges it again: a resumed consumer's year, a consumer's year at a new tier. A canceled year ends with its period.
     * A plan change arranged for the end of the period goes back to `billing:run`, which arranges it again or applies it
     * when it comes due.
     */
    private function letGoOfTheSchedule(Subscription $subscription): void
    {
        $schedule = $subscription->provider_schedule_id;

        if ($schedule === null) {
            return;
        }

        new StripeAnnualTerms($this->stripe)->release($schedule);
        $subscription->forceFill(['provider_schedule_id' => null, 'scheduled_swap_arranged_at' => null])->save();
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
