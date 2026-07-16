<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Support\LinkOut;
use Pushery\Billing\Support\SafeExternalUrl;
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
            // When an external merchant of record owns billing (config billing.link_out), the hub links OUT to
            // its portal instead of offering the in-app checkout below — the app is not the merchant of record.
            'linkOut' => app(LinkOut::class)->url(),
            'canSwap' => $this->hasLiveSubscription(),
            // The "includes an X-day free trial" hint is about the SUBSCRIPTION trial the owner gets on
            // subscribing, so it follows subscriptionTrialEnabled — a generic (pre-subscription) trial does
            // not belong on a plan row.
            'trialDays' => $trialPolicy->subscriptionTrialEnabled() ? $trialPolicy->days() : null,
            // The trial-status note (remaining days + card hint) shown while the owner is on a trial.
            'trial' => app(TrialCallouts::class)->for($this->owner(), $this->currentState(), $this->subscription()?->trial_ends_at),
            // Whether the typed coupon code is recognized, so the visitor sees it applied before checkout.
            'couponStatus' => $this->couponStatus(),
            // The card a swap or subscription will charge, mirrored from local columns — never a provider call.
            'cardOnFile' => $this->cardOnFile(),
            // The purchasable one-time add-ons (top-ups), rendered in the #addons section the usage screen links to.
            'addons' => $this->addonOptions(),
            'options' => array_map(static fn (Plan $plan): array => [
                'key' => $plan->key,
                'label' => $tiers->label($plan->key),
                'price' => $plan->amount->format(),
                'interval' => $plan->interval->value,
            ], $plans),
        ]);
    }

    /**
     * The card on file, mirrored from the local `pm_type` / `pm_last_four` columns (never a provider call), so
     * the owner sees which card a swap or subscription will charge. Null when no card is stored.
     *
     * @return array{brand: string, last4: string}|null
     */
    private function cardOnFile(): ?array
    {
        $owner = $this->owner();
        $brand = $owner->getAttribute('pm_type');
        $last4 = $owner->getAttribute('pm_last_four');

        if (! is_string($brand) || $brand === '' || ! is_string($last4) || $last4 === '') {
            return null;
        }

        return ['brand' => $brand, 'last4' => $last4];
    }

    /**
     * The purchasable one-time add-ons (top-ups) — key, label, and formatted price from the catalog. The
     * client only ever submits a KEY; the price is resolved server-side, mirroring the tier allowlist so a
     * client can never inject a price. Only add-ons with a configured display price are offered.
     *
     * @return list<array{key: string, label: string, price: string}>
     */
    private function addonOptions(): array
    {
        $catalog = app(ConfigAddonCatalog::class);
        $out = [];

        foreach ($catalog->all() as $key) {
            $price = $catalog->priceFor($key);

            if ($price instanceof Money) {
                $out[] = ['key' => $key, 'label' => $catalog->label($key), 'price' => $price->format()];
            }
        }

        return $out;
    }

    /**
     * The one entrance action. An owner who already has a live subscription SWAPS to the tier in-app; an
     * owner who does not is taken to the hosted checkout to become a subscriber. The mirror guard is the
     * point: without the hasLiveSubscription() branch, an owner with a subscription could open a SECOND
     * one at the provider (a live money bug — two subscriptions, double-billed).
     */
    public function subscribe(string $tierKey): void
    {
        $this->denyInAppCheckout();
        $this->ensureEligible();

        if ($this->hasLiveSubscription()) {
            $this->swap($tierKey);

            return;
        }

        $coupon = trim($this->couponCode);
        $coupon = $coupon !== '' ? $coupon : null;

        $intent = app(Checkout::class)->subscribe($this->owner(), $tierKey, $coupon);
        $url = SafeExternalUrl::orNull($intent->payload['checkout_url'] ?? null);

        if ($url !== null) {
            // The subscription itself is not real yet — it becomes real on the checkout return, where the
            // plan-sync effect records plan.granted. This only marks that the customer started checkout.
            $this->audit('checkout.started', ['tier' => $tierKey, 'coupon' => $coupon]);

            // A full-page redirect to the provider's hosted checkout — validated to be an absolute http(s)
            // URL first, so a tampered payload can never bounce the customer to a script/open-redirect target.
            $this->redirect($url);
        }
    }

    /**
     * Buy a one-time add-on (a top-up) via the provider's hosted mode:payment checkout. Like subscribe(), the
     * client submits only the add-on KEY — the catalog resolves the price server-side (anti-price-injection) —
     * and an unknown key is refused before any charge. The hosted URL is scheme-validated before the redirect;
     * a driver with no checkout URL yields nothing (no redirect).
     */
    public function purchaseAddon(string $addonKey): void
    {
        $this->denyInAppCheckout();
        $this->ensureEligible();

        abort_unless(app(ConfigAddonCatalog::class)->exists($addonKey), 404);

        $intent = app(OneTimeCharge::class)->purchase($this->owner(), $addonKey);
        $url = SafeExternalUrl::orNull($intent->payload['checkout_url'] ?? null);

        if ($url !== null) {
            $this->audit('addon.checkout.started', ['addon' => $addonKey]);

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
        $this->denyInAppCheckout();
        $this->ensureEligible();

        app(SubscriptionActions::class)->swap($this->owner(), $tierKey);

        $this->audit('subscription.swapped', ['tier' => $tierKey]);

        $this->previewTierKey = null;
        $this->previewAmount = null;
    }

    /**
     * Guard every in-app money-moving action at the SERVER, not just the view. In external-MoR link-out mode
     * the app is not the merchant of record, so no in-app checkout may run: the Blade view hides the controls,
     * and this refuses a crafted request (`$wire.subscribe(...)`) that would otherwise reach a public Livewire
     * method regardless of whether its button was rendered.
     */
    private function denyInAppCheckout(): void
    {
        abort_if(app(LinkOut::class)->active(), 403);
    }
}
