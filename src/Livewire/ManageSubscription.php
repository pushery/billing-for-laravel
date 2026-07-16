<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Support\TrialCallouts;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The account-hub plan-change screen — the in-app upgrade/downgrade that replaces delegating plan
 * changes to a hosted portal. It offers the plans purchasable from the current tier (resolved from the
 * catalog, never the client) and swaps to the chosen one by its KEY, so a client can never submit a
 * price. Before committing, the customer can preview what the mid-cycle change will cost: the proration
 * strategy asks the provider for the net amount due (null when it cannot be previewed — the UI degrades
 * rather than showing a wrong figure). Stripe books the proration itself when the swap executes.
 */
final class ManageSubscription extends AccountScreen
{
    /**
     * The tier key the last preview was computed for, so the estimate is shown against the right row.
     * Locked: written only by preview()/swap(), never bound to client input.
     */
    #[Locked]
    public ?string $previewTierKey = null;

    /**
     * The previewed net amount due for the pending swap, or null when it could not be previewed. Locked:
     * written only by preview()/swap(), never bound to client input.
     */
    #[Locked]
    public ?string $previewAmount = null;

    /**
     * A coupon code the visitor types to apply at checkout. Deliberately NOT locked — it is client input,
     * resolved by the DiscountResolver (never trusted as an amount) and applied only by the driver.
     */
    public string $couponCode = '';

    public function render(): View
    {
        $tiers = app(TierCatalog::class);
        $key = $this->currentTierKey();

        // Fail-soft: even if the owner's tier is not a configured tier, still offer the plans. A visitor
        // on the zero tier must always be able to reach checkout — never a blank screen.
        $current = $tiers->find($key) ?? new TierIdentity(key: $key, label: $tiers->label($key));

        $plans = app(PlanCatalog::class)->options($current);

        $trialPolicy = app(TrialPolicy::class);

        return $this->view('billing::livewire.manage-subscription', [
            'currentLabel' => $tiers->label($key),
            'canSwap' => $this->hasLiveSubscription(),
            // The "includes an X-day free trial" hint is about the SUBSCRIPTION trial the owner gets on
            // subscribing, so it follows subscriptionTrialEnabled — a generic (pre-subscription) trial does
            // not belong on a plan row.
            'trialDays' => $trialPolicy->subscriptionTrialEnabled() ? $trialPolicy->days() : null,
            // The trial-status note (remaining days + card hint) shown while the owner is on a trial.
            'trial' => app(TrialCallouts::class)->for($this->owner(), $this->currentState(), $this->subscription()?->trial_ends_at),
            // Whether the typed coupon code is recognized, so the visitor sees it applied before checkout.
            'couponStatus' => $this->couponStatus(),
            'options' => array_map(static fn (Plan $plan): array => [
                'key' => $plan->key,
                'label' => $tiers->label($plan->key),
                'price' => $plan->amount->format(),
                'interval' => $plan->interval->value,
            ], $plans),
        ]);
    }

    /**
     * The one entrance action. An owner who already has a live subscription SWAPS to the tier in-app; an
     * owner who does not is taken to the hosted checkout to become a subscriber. The mirror guard is the
     * point: without the hasLiveSubscription() branch, an owner with a subscription could open a SECOND
     * one at the provider (a live money bug — two subscriptions, double-billed).
     */
    public function subscribe(string $tierKey): void
    {
        $this->ensureEligible();

        if ($this->hasLiveSubscription()) {
            $this->swap($tierKey);

            return;
        }

        $coupon = trim($this->couponCode);
        $coupon = $coupon !== '' ? $coupon : null;

        $intent = app(Checkout::class)->subscribe($this->owner(), $tierKey, $coupon);
        $url = $intent->payload['checkout_url'] ?? null;

        if (is_string($url) && $url !== '') {
            // The subscription itself is not real yet — it becomes real on the checkout return, where the
            // plan-sync effect records plan.granted. This only marks that the customer started checkout.
            $this->audit('checkout.started', ['tier' => $tierKey, 'coupon' => $coupon]);

            // A full-page redirect to the provider's hosted checkout. redirect() returns void in Livewire.
            $this->redirect($url);
        }
    }

    /**
     * Whether the typed coupon code is recognized: null when the field is empty, 'applied' when the
     * DiscountResolver resolves it (a real, unexpired coupon), 'invalid' otherwise — so the visitor sees
     * the code take before they are sent to the hosted checkout. The actual discount is applied by the
     * driver; a code with no provider mapping resolves here but simply discounts nothing at the provider.
     */
    private function couponStatus(): ?string
    {
        $code = trim($this->couponCode);

        if ($code === '') {
            return null;
        }

        return app(DiscountResolver::class)->resolve($code) !== null ? 'applied' : 'invalid';
    }

    /**
     * Preview the net cost of swapping to a tier now, without committing. The plan is resolved from the
     * catalog by key (never a client price), and the proration strategy returns null when the change
     * cannot be previewed — so the row shows "no estimate" instead of a misleading number.
     */
    public function preview(string $tierKey): void
    {
        $plan = app(PlanCatalog::class)->planFor($tierKey);

        $amount = $plan instanceof Plan
            ? app(ProrationStrategy::class)->previewSwap($this->owner(), $plan)
            : null;

        $this->previewTierKey = $tierKey;
        $this->previewAmount = $amount instanceof Money ? $amount->format() : null;
    }

    public function swap(string $tierKey): void
    {
        $this->ensureEligible();

        app(SubscriptionActions::class)->swap($this->owner(), $tierKey);

        $this->audit('subscription.swapped', ['tier' => $tierKey]);

        $this->previewTierKey = null;
        $this->previewAmount = null;
    }
}
