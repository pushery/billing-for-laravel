<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Consumer\PurchaseDeclarations;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\SwapTiming;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BuyerAudiences;
use Pushery\Billing\Support\CatalogLabel;
use Pushery\Billing\Support\LinkOut;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\Support\LocalizedMoney;
use Pushery\Billing\Support\PlanSwapPlanner;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\Support\TrialCallouts;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The account-hub plan-change screen — the in-app upgrade/downgrade that replaces delegating plan
 * changes to a hosted portal. It offers the plans purchasable from the current tier (resolved from the
 * catalog, never the client) and swaps to the chosen one by its KEY, so a client can never submit a
 * price. Before committing, the customer can preview what the mid-cycle change will cost: the proration
 * strategy asks the provider for the net amount due (null when it cannot be previewed — the UI degrades
 * rather than showing a wrong figure). Stripe books the proration itself when the swap executes.
 *
 * It swaps one contract, the default type at the platform. A contract of another type is swapped through
 * SubscriptionActions with its type, from a screen the host provides.
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
     * carried as a CODE (never trusted as an amount) and honored only by the driver that was configured.
     */
    public string $couponCode = '';

    protected function headingKey(): string
    {
        return 'billing::account.manage.heading';
    }

    public function render(): View
    {
        $tiers = Container::getInstance()->make(TierCatalog::class);
        $key = $this->currentTierKey();

        // Fail-soft: even if the owner's tier is not a configured tier, still offer the plans. A visitor
        // on the zero tier must always be able to reach checkout — never a blank screen.
        $current = $tiers->find($key) ?? new TierIdentity(key: $key, label: $tiers->label($key));

        $plans = $this->offeredPlans($current);

        $trialPolicy = Container::getInstance()->make(TrialPolicy::class);

        // Resolved into a variable rather than written as a ternary inside the array below, and the reason
        // is measurable rather than stylistic: pcov attributes the arms of a multi-line ternary to a single
        // line, so one of them counts as never executed however the branch goes. Under a 100% floor that
        // reads as a missing test, and the case it asks for already exists. `TernaryInAnArrayIsNotCoverableTest`
        // keeps the shape out.
        $trialDays = null;

        if ($trialPolicy->subscriptionTrialEnabled() && ! Subscription::ownerHasHadATrial($this->owner())) {
            $trialDays = $trialPolicy->days();
        }

        return $this->view('billing::livewire.manage-subscription', [
            'currentLabel' => CatalogLabel::translate($tiers->label($key)),
            // When an external merchant of record owns billing (config billing.link_out), the hub links OUT to
            // its portal instead of offering the in-app checkout below — the app is not the merchant of record.
            'linkOut' => Container::getInstance()->make(LinkOut::class)->url(),
            'canSwap' => $this->hasLiveSubscription(),
            // A downgrade the customer scheduled but that has not taken effect yet: shown as "changes on
            // {date}" with a cancel action, so a pending change is never a surprise on the next invoice.
            'scheduledSwap' => $this->scheduledSwap(),
            // The "includes an X-day free trial" hint is about the SUBSCRIPTION trial the owner gets on
            // subscribing, so it follows subscriptionTrialEnabled — a generic (pre-subscription) trial does
            // not belong on a plan row.
            //
            // AND WHETHER THIS OWNER WOULD ACTUALLY GET ONE, which the config alone cannot say since the
            // trial became once-per-owner. A returning customer was shown "includes a 14-day free trial",
            // pressed Subscribe, and reached a paid checkout: the screen promising what the starter
            // refuses. Both read one answer now, so the promise and the grant cannot disagree.
            'trialDays' => $trialDays,
            // The trial-status note (remaining days + card hint) shown while the owner is on a trial.
            'trial' => Container::getInstance()->make(TrialCallouts::class)->for($this->owner(), $this->currentState(), $this->subscription()?->trial_ends_at),
            // Whether the typed coupon code is recognized, so the visitor sees it applied before checkout.
            'couponStatus' => $this->couponStatus(),
            // The card a swap or subscription will charge, mirrored from local columns — never a provider call.
            'cardOnFile' => $this->cardOnFile(),
            // The purchasable one-time add-ons (top-ups), rendered in the #addons section the usage screen links to.
            'addons' => $this->addonOptions(),
            'options' => array_map(static fn (Plan $plan): array => [
                'key' => $plan->key,
                'label' => CatalogLabel::translate($tiers->label($plan->key)),
                'price' => LocalizedMoney::format($plan->amount),
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
     * client can never inject a price. Only add-ons with a configured display price are offered, and none at all
     * where the driver binds no way to sell one.
     *
     * @return list<array{key: string, label: string, price: string}>
     */
    private function addonOptions(): array
    {
        if (! Container::getInstance()->bound(OneTimeCharge::class)) {
            return [];
        }

        $catalog = Container::getInstance()->make(ConfigAddonCatalog::class);
        $audiences = Container::getInstance()->make(BuyerAudiences::class);
        $owner = $this->owner();
        $out = [];

        foreach ($catalog->all() as $key) {
            if (! $audiences->mayBuyAddon($owner, $key)) {
                continue;
            }

            $price = $catalog->priceFor($key);

            if ($price instanceof Money) {
                $out[] = ['key' => $key, 'label' => CatalogLabel::translate($catalog->label($key)), 'price' => LocalizedMoney::format($price)];
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
    public function subscribe(string $tierKey, ?string $declarationReference = null): void
    {
        $this->denyInAppCheckout();
        $this->ensureEligible();

        if ($this->hasLiveSubscription()) {
            $this->swap($tierKey);

            return;
        }

        // The key comes from the client, so it is held against the plans the screen lists, as swap() and preview()
        // hold it: a crafted request for a tier the screen does not offer, an untouchable one among them, opens nothing.
        $this->refuseATierNotOffered($tierKey);

        // An offer only businesses may buy is not shown to anybody else, so it is not sold to them either.
        if (! Container::getInstance()->make(BuyerAudiences::class)->mayBuyTier($this->owner(), $tierKey)) {
            throw new NotFoundHttpException;
        }

        $coupon = Str::trim($this->couponCode);
        $coupon = $coupon !== '' ? $coupon : null;

        // BEFORE the provider is asked for anything, for the reasons purchaseAddon() below gives. Silent without
        // a consumer-rights profile, and a plain subscription passes under a profile that lets one start without
        // declarations; only a tier classified as something whose right ends at provision is refused here.
        Container::getInstance()->make(PurchaseDeclarations::class)
            ->assertMaySubscribe($this->owner(), $tierKey, $declarationReference);

        // `StartsSubscriptions`, not `Checkout`. Only the hosted-checkout driver binds `Checkout`, so asking
        // for it here would resolve an unbound interface under every other driver and end the button in a
        // `BindingResolutionException`. This contract is what every driver answers, each in its own shape.
        $start = Container::getInstance()->make(StartsSubscriptions::class)->start($this->owner(), $tierKey, $coupon, $declarationReference);
        $url = SafeExternalUrl::orNull($start->checkoutUrl);

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
    public function purchaseAddon(string $addonKey, ?string $declarationReference = null): void
    {
        $this->denyInAppCheckout();
        $this->ensureEligible();

        // The same answer as an unknown key, because on a driver that sells no add-ons none of them is offered.
        if (! Container::getInstance()->bound(OneTimeCharge::class)) {
            throw new NotFoundHttpException;
        }

        if (! (Container::getInstance()->make(ConfigAddonCatalog::class)->exists($addonKey))) {
            throw new NotFoundHttpException;
        }

        // The same answer for an add-on only businesses may buy, which this owner was never shown.
        if (! Container::getInstance()->make(BuyerAudiences::class)->mayBuyAddon($this->owner(), $addonKey)) {
            throw new NotFoundHttpException;
        }

        // BEFORE the provider is asked for anything. The gate at provision already refuses a work whose
        // right of withdrawal has not been safely extinguished, but by then the buyer has paid — the
        // operator is left refunding a sale the package could have declined for free. Same rule, both ends.
        //
        // Silent on an install with no consumer-rights profile: the check returns before it looks anything
        // up, so nothing about this method changes for the installs that are the overwhelming majority.
        //
        // It is left to surface as the domain exception rather than folded into the 403 the two guards above
        // raise, and that is deliberate. A 403 says "you may not", which is not what happened: the install
        // turned a consumer-rights profile on and is selling, through the package's own button, a product
        // whose declarations this screen cannot collect — the notice wording is the operator's and their
        // adviser's, so the package will never render it. That is a wiring mistake with a precise remedy,
        // and the exception names it. Dressing it as an authorization failure would hide the one sentence
        // an operator needs.
        Container::getInstance()->make(PurchaseDeclarations::class)
            ->assertMayCheckout($this->owner(), $addonKey, $declarationReference);

        $intent = Container::getInstance()->make(OneTimeCharge::class)->purchase($this->owner(), $addonKey, $declarationReference);
        $url = SafeExternalUrl::orNull($intent->payload['checkout_url'] ?? null);

        if ($url !== null) {
            $this->audit('addon.checkout.started', ['addon' => $addonKey]);

            $this->redirect($url);
        }
    }

    /**
     * Whether the typed coupon code is recognized: null when the field is empty, 'applied' when the
     * configured driver would actually apply it, 'invalid' otherwise — so the visitor sees the code take
     * before they commit to anything.
     *
     * ASKED OF THE DRIVER, not of the catalog, and the difference is money. The `DiscountResolver` — the
     * config-defined coupon map — resolves a code with no provider mapping that then discounts nothing at the
     * provider, and a driver that bills locally reads a different catalog entirely. Either way, asking the
     * catalog would tell the visitor their code took and then charge them in full, with nothing anywhere
     * saying so.
     */
    private function couponStatus(): ?string
    {
        $code = Str::trim($this->couponCode);

        if ($code === '') {
            return null;
        }

        return Container::getInstance()->make(StartsSubscriptions::class)->honorsCoupon($code) ? 'applied' : 'invalid';
    }

    /**
     * Preview the net cost of swapping to a tier now, without committing. The plan is resolved from the
     * catalog by key (never a client price), and the proration strategy returns null when the change
     * cannot be previewed — so the row shows "no estimate" instead of a misleading number.
     */
    public function preview(string $tierKey): void
    {
        $this->refuseATierNotOffered($tierKey);
        $this->throttle('swap-preview', [60 => 10, 3600 => 60, 86400 => 240]);

        $plan = Container::getInstance()->make(PlanCatalog::class)->planFor($tierKey);

        $amount = $plan instanceof Plan
            ? Container::getInstance()->make(ProrationStrategy::class)->previewSwap($this->owner(), $plan)
            : null;

        $this->previewTierKey = $tierKey;
        $this->previewAmount = $amount instanceof Money ? LocalizedMoney::format($amount) : null;
    }

    public function swap(string $tierKey): void
    {
        $this->denyInAppCheckout();
        $this->ensureEligible();
        $this->refuseATierNotOffered($tierKey);
        $this->throttle('swap', [60 => 5]);

        $subscription = $this->subscription();
        $timing = $subscription instanceof Subscription
            ? Container::getInstance()->make(PlanSwapPlanner::class)->timingFor($this->currentTierKey(), $tierKey)
            : SwapTiming::Immediate;

        // A move to the same tier is a no-op the planner reports as null — don't call the provider or
        // schedule an empty change.
        if ($timing === null) {
            $this->previewTierKey = null;
            $this->previewAmount = null;

            return;
        }

        // A downgrade waits for the period end (the current cycle is already paid at the higher tier). It is
        // recorded, shown, and cancellable until then — the provider is not touched now; ScheduledSwapRunner
        // performs the swap when the date arrives. An upgrade takes effect immediately.
        //
        // `$subscription` IS NON-NULL HERE BY CONSTRUCTION, AND NOTHING RESTATES IT. `PeriodEnd` is
        // reachable only where a subscription was resolved, because the other branch of the ternary
        // above answers `Immediate`. An `instanceof` conjunct saying so was added here and taken
        // straight back out: the analyzer this package pins derives the relationship and reports the
        // conjunct as always true, so the line written to make the gate and a developer machine agree
        // is the line that made them disagree. What produced it was a local vendor tree two analyzer
        // releases behind the pin, which DevToolPinsTest now reports rather than leaving to be found
        // by a red integration run.
        if ($timing === SwapTiming::PeriodEnd) {
            $subscription->scheduleSwap($tierKey, $subscription->current_period_end ?? Carbon::now()->utc());
            $this->audit('subscription.swap_scheduled', ['tier' => $tierKey]);
        } else {
            // The screen books no proration of its own. `SubscriptionActions::swap()` prorates unless told
            // otherwise: the local driver through the bound strategy, priced against the tier being left before
            // the tier moves, and Stripe at Stripe. A booking here as well would count the same days twice.
            //
            // Wrapped so a swap that fails leaves nothing behind, neither the proration the driver booked nor
            // the pending downgrade the upgrade would have superseded.
            DB::transaction(function () use ($tierKey, $subscription): void {
                Container::getInstance()->make(SubscriptionActions::class)->swap($this->owner(), $tierKey);

                // An immediate upgrade supersedes any pending downgrade — the customer just chose to move up now.
                $subscription?->cancelScheduledSwap();
            });

            $this->audit('subscription.swapped', ['tier' => $tierKey]);
        }

        $this->previewTierKey = null;
        $this->previewAmount = null;
    }

    /**
     * Refuse an owner who has used this action more often in a window than a customer clicking through the plans
     * ever would.
     *
     * One Livewire request may carry up to 50 calls on each of up to 200 component entries, the framework's
     * `payload.max_calls` and `max_components`, and a preview asks the provider twice on the platform's account.
     * Without a limit, a single request drives thousands of provider calls. The refusal ends the request rather than
     * answering each further call with nothing, so the rest of the batch never runs.
     *
     * A minute alone does not bound a month. The provider allots an account its reads per month, and ten previews a
     * minute held all month come to over 400,000 reads for one owner, so the preview also carries an hour and a day.
     *
     * @param  array<int, int>  $limits  how many calls each window allows, keyed by the window's length in seconds
     */
    private function throttle(string $action, array $limits): void
    {
        $owner = $this->owner();
        $id = $owner->getKey();
        $key = 'billing:'.$action.':'.$owner->getMorphClass().':'.(is_scalar($id) ? (string) $id : '');

        foreach ($limits as $seconds => $allowed) {
            if (RateLimiter::tooManyAttempts("{$key}:{$seconds}", $allowed)) {
                throw new TooManyRequestsHttpException(RateLimiter::availableIn("{$key}:{$seconds}"));
            }
        }

        foreach (array_keys($limits) as $seconds) {
            RateLimiter::hit("{$key}:{$seconds}", $seconds);
        }
    }

    /**
     * The plans this owner is offered: the catalog's options, less any tier only businesses may buy where the owner
     * is not one. The screen lists these and a swap or a preview is refused for anything else, so both read one list.
     *
     * @return list<Plan>
     */
    private function offeredPlans(TierIdentity $current): array
    {
        $audiences = Container::getInstance()->make(BuyerAudiences::class);
        $owner = $this->owner();

        return array_values(array_filter(
            Container::getInstance()->make(PlanCatalog::class)->options($current),
            static fn (Plan $plan): bool => $audiences->mayBuyTier($owner, $plan->key),
        ));
    }

    /**
     * Refuse a tier key the screen does not offer, the way an unknown add-on key is refused.
     *
     * The key comes from the client, so it is held against the set the screen offers before anything is previewed,
     * scheduled or swapped. A tier outside that set ranks as a downgrade and would be scheduled for the period end,
     * where no run can apply it. The owner's own tier passes: a second submit of the same swap finds the owner on it
     * already, and that is a no-op rather than an error.
     */
    private function refuseATierNotOffered(string $tierKey): void
    {
        $key = $this->currentTierKey();

        if ($tierKey === $key) {
            return;
        }

        $tiers = Container::getInstance()->make(TierCatalog::class);
        $current = $tiers->find($key) ?? new TierIdentity(key: $key, label: $tiers->label($key));

        foreach ($this->offeredPlans($current) as $plan) {
            if ($plan->key === $tierKey) {
                return;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * The pending scheduled swap for the view: the target tier's label and the date it takes effect, or
     * null when nothing is scheduled.
     *
     * @return array{tierLabel: string, date: string}|null
     */
    private function scheduledSwap(): ?array
    {
        $subscription = $this->subscription();

        if (! $subscription instanceof Subscription || ! $subscription->hasScheduledSwap()) {
            return null;
        }

        $tierKey = $subscription->scheduled_tier_key ?? '';

        return [
            'tierLabel' => CatalogLabel::translate(Container::getInstance()->make(TierCatalog::class)->label($tierKey)),
            'date' => (string) LocalizedDate::long($subscription->scheduled_swap_at ?? Carbon::now()),
        ];
    }

    /** Drop a downgrade the customer scheduled but changed their mind about, before it takes effect. */
    public function cancelScheduledSwap(): void
    {
        $this->denyInAppCheckout();

        $subscription = $this->subscription();

        if ($subscription instanceof Subscription && $subscription->hasScheduledSwap()) {
            $subscription->cancelScheduledSwap();
            $this->audit('subscription.swap_schedule_canceled', []);
        }
    }

    /**
     * Guard every in-app money-moving action at the SERVER, not just the view. In external-MoR link-out mode
     * the app is not the merchant of record, so no in-app checkout may run: the Blade view hides the controls,
     * and this refuses a crafted request (`$wire.subscribe(...)`) that would otherwise reach a public Livewire
     * method regardless of whether its button was rendered.
     */
    private function denyInAppCheckout(): void
    {
        if (Container::getInstance()->make(LinkOut::class)->active()) {
            throw new HttpException(403);
        }
    }
}
