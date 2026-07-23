<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\CancellationReason;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\SubscriptionItem;

/**
 * The Stripe implementation of the one provider-mutating subscription seam. It reads the provider
 * subscription reference from the package's local subscription-state row and mutates the Stripe
 * subscription directly; the resulting `customer.subscription.*` webhook syncs the local row back.
 *
 * The swap is the superset closure that keeps upgrades/downgrades in-app: the client submits a tier
 * KEY, never a price, and the price is resolved from the plan catalog (anti-price-injection). A
 * cancel/resume/cancelNow with no live subscription is a safe no-op; a swap without one, to a tier
 * that carries no provider price, or onto a subscription whose tier item cannot be identified, is
 * rejected rather than silently doing nothing — or, worse, repricing the wrong item.
 */
final readonly class StripeSubscriptionActions implements SubscriptionActions
{
    public function __construct(
        private StripeClient $stripe,
        private PlanCatalog $plans,
        private StripeSubscriptionItems $items,
        private CanTransactMoney $eligibility,
    ) {}

    public function cancel(Model $billable, ?CancellationSurvey $survey = null): void
    {
        $reference = $this->subscriptionReference($billable);

        if ($reference === null) {
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

        $this->ignoringDeadSubscription(fn () => $this->stripe->subscriptions->update($reference, $payload));
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

    public function resume(Model $billable): void
    {
        $reference = $this->subscriptionReference($billable);

        if ($reference !== null) {
            $this->ignoringDeadSubscription(fn () => $this->stripe->subscriptions->update($reference, ['cancel_at_period_end' => false]));
        }
    }

    public function cancelNow(Model $billable): void
    {
        $reference = $this->subscriptionReference($billable);

        if ($reference !== null) {
            $this->ignoringDeadSubscription(fn () => $this->stripe->subscriptions->cancel($reference));
        }
    }

    public function swap(Model $billable, string $tierKey, bool $prorate = true): void
    {
        // Defense in depth: a swap reprices the subscription and books a proration — a money movement — so
        // refuse it for an ineligible owner even if a caller bypassed the UI eligibility guard (mirrors
        // StripeCheckout / StripeOneTimeCharge). cancel/resume/cancelNow move no money and stay ungated so
        // account deletion can always cancel.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $price = $this->plans->providerPriceFor($tierKey);

        if ($price === null) {
            throw new InvalidArgumentException("Tier '{$tierKey}' has no provider price to swap to.");
        }

        $reference = $this->subscriptionReference($billable);

        if ($reference === null) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($reference);
        } catch (InvalidRequestException) {
            throw new InvalidArgumentException('Cannot swap: the billable has no active subscription.');
        }

        $base = $this->items->base($subscription);

        if (! $base instanceof SubscriptionItem) {
            throw new RuntimeException("Cannot swap: the tier item on Stripe subscription {$reference} cannot be identified.");
        }

        // Only the base item is repriced. Any metered component or app-owned item on the subscription is
        // absent from the payload, which Stripe leaves exactly as it is — repricing it would destroy it.
        $this->stripe->subscriptions->update($reference, [
            'items' => [['id' => $base->id, 'price' => $price]],
            'proration_behavior' => $prorate ? 'create_prorations' : 'none',
        ]);
    }

    /**
     * Run a subscription mutation, swallowing the error Stripe raises when the subscription is already
     * canceled or gone. cancel/resume/cancelNow must be a safe no-op on a dead subscription — account
     * deletion calls cancelNow and must never be blocked by an already-canceled subscription.
     *
     * @param  callable(): mixed  $call
     */
    private function ignoringDeadSubscription(callable $call): void
    {
        try {
            $call();
        } catch (InvalidRequestException) {
            // Already canceled or no longer exists: nothing to do.
        }
    }

    /** The provider subscription reference from the billable's local subscription row, or null. */
    private function subscriptionReference(Model $billable): ?string
    {
        $subscription = Subscription::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return $subscription?->provider_id;
    }
}
