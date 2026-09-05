<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Discounts\CouponRedeemer;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\CouponUnavailable;
use Pushery\Billing\Exceptions\SubscriptionNotPermitted;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\SubscriptionIntent;
use Pushery\Billing\Trials\TrialMode;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubscriptionStart;
use RuntimeException;

/**
 * Starting a subscription where the provider runs no billing cycle of its own.
 *
 * ## Three shapes, and none of them is the other two
 *
 * A **generic trial** unlocks a tier on the OWNER and creates no subscription at all — there is nothing to
 * bill yet and nobody to send anywhere.
 *
 * A **subscription trial that needs no payment method** creates the row immediately, in `trialing`. There
 * is no payment to wait for, so waiting would only mean the customer cannot use what they were promised.
 * When the trial ends and no mandate has appeared, the cycle finds nothing to charge and the subscription
 * goes `incomplete` rather than into dunning — the customer was told no card was needed, no payment was
 * ever attempted with their consent, and mailing them "your payment failed" is a support case about money
 * that never existed. `LocalBillingEngine` makes that distinction; it is not free.
 *
 * Everything else **redirects**, and this is the shape the whole design is built around.
 *
 * ## Nothing is written until the mandate arrives, and that is the point
 *
 * The obvious design writes the subscription first, in a pending state, and rolls it back when the
 * customer abandons the redirect. That needs a sweep for abandoned activations, and until it runs the
 * subscriptions table holds a row describing something that did not happen.
 *
 * Here the redirect leaves a {@see SubscriptionIntent} and nothing else. A customer who closes the tab
 * leaves an unclaimed intent: no access, no charge, no half subscription, and nothing to clean up. The
 * `activating` state the screens show while they poll is a reading of that moment rather than a row —
 * it decays with the session, because there is nothing to decay.
 *
 * ## The tier key is the only thing the caller chooses
 *
 * Never an amount. The key is resolved against the configured catalog and an unknown one is refused
 * rather than defaulted, because it arrives from a browser and a default would let whoever sent it decide
 * what a subscription costs.
 */
final readonly class LocalSubscriptionStarter implements StartsSubscriptions
{
    public function __construct(
        private string $provider,
        private TierCatalog $tiers,
        private PlanCatalog $plans,
        private TrialPolicy $policy,
        private Trials $genericTrials,
        private EnsuresProviderCustomer $customers,
        private EstablishesMandateByRedirect $mandates,
        private Repository $config,
        private CheckoutUrls $urls,
        private CouponRedeemer $coupons,
    ) {}

    public function start(Model $billable, string $tierKey, ?string $couponCode = null): SubscriptionStart
    {
        // The key has to be a KEY of the tier map, not a path INTO it. The catalog resolves
        // `billing.tiers.{$key}` through dot notation, so `pro.price_display` reaches a node that is an
        // array — and `find()` therefore says yes to a tier that does not exist. The subscription is then
        // written against it: Active in every report, never billable, sitting permanently due and writing a
        // warning on each scheduled run.
        //
        // Checked against the catalog's own key set rather than by rejecting dots, because the rule is not
        // "no dots" — it is "one of the keys this install actually sells", and the browser is what supplies
        // the value.
        // ONE check, not two. `find()` was asked first and its null was treated as the refusal — which is
        // wrong twice over: it resolves `billing.tiers.{$key}` through DOT NOTATION, so `pro.price_display`
        // reaches a node that happens to be an array and passes, and it is redundant once the key set is
        // consulted, because both read the same configuration. A guard no run can enter is a guard nobody
        // can trust, and the coverage floor is what says so.
        // FIRST, because everything after it reads the key. Left where it naturally belongs — beside the
        // write that uses it — the guard could never fire: `alreadySubscribed()` passes the key straight
        // into a WHERE, so an exotic one dies inside the query builder several steps earlier, with a
        // message about SQL rather than about the billable. Hoisted, it is both reachable and useful.
        $ownerKey = $this->ownerKey($billable);
        $couponCode = $this->normalizeCode($couponCode);

        if (! array_key_exists($tierKey, $this->tiers->all())) {
            throw SubscriptionNotPermitted::unknownTier($tierKey);
        }

        if ($this->tiers->isUntouchable($tierKey)) {
            throw SubscriptionNotPermitted::untouchableTier($tierKey);
        }

        if ($this->alreadySubscribed($billable)) {
            throw SubscriptionNotPermitted::alreadySubscribed($billable->getMorphClass().'#'.$ownerKey);
        }

        $now = CarbonImmutable::now();

        // A generic trial is checked first because it is the only shape that is not a subscription. Asking
        // about the subscription trial first would attach one on top of it, which is the double trial the
        // policy's own mode derivation exists to prevent.
        //
        // ONLY FOR SOMEBODY WHO HAS NOT HAD ONE. `Trials::grant()` is idempotent for a trial that is still
        // RUNNING — it hands the existing end back — and it is not idempotent for one that has LAPSED: it
        // writes a fresh end. Called from a buy button that turns Subscribe into an unlimited renewal:
        // press it again after the fourteen days and get fourteen more, as often as you like. Nothing looks
        // wrong, because every call reports success and the customer simply never pays.
        //
        // The column is checked directly rather than through `onGenericTrial()`, which answers "is one
        // running" and is false for exactly the lapsed case this has to catch.
        if ($this->policy->mode($tierKey) === TrialMode::Generic && $billable->getAttribute('trial_ends_at') === null) {
            $granted = $this->genericTrials->grant($billable);

            if ($granted instanceof CarbonInterface) {
                return new SubscriptionStart(SubscriptionState::GenericTrial);
            }

            // A generic mode with a zero length grants nothing. Falling through rather than returning a
            // trial state nobody is on: the caller would show a trial that does not exist, and the customer
            // would find out when access did not arrive.
        }

        // ONCE PER OWNER, the same rule the generic trial states a few lines above — and it was missing
        // here, on the shape that costs money. `subscriptionTrialEnabled()` and `endsAt()` are computed
        // from CONFIG and know nothing about history, so every call handed out a fresh trial. Nothing
        // refused a second one: `alreadySubscribed()` deliberately lets an owner whose row is `ended` or
        // `incomplete_expired` come back, and both are one button away — `cancelNow()` writes `ended`
        // immediately, and a lapsed card-less trial now writes `incomplete_expired`. So: take the free
        // trial, cancel, subscribe again, take it again, as often as you like, never paying.
        //
        // Read from the subscription row rather than a counter, because there is exactly one row per
        // (owner, type, merchant_uid) — every writer here upserts on that key — and its `trial_ends_at`
        // survives the row being reused. A trial that was had is a trial that shows.
        $trialEndsAt = $this->policy->subscriptionTrialEnabled($tierKey) && ! Subscription::ownerHasHadATrial($billable)
            ? $this->policy->endsAt($now, $tierKey)
            : null;

        if ($trialEndsAt instanceof DateTimeImmutable && ! $this->policy->requiresPaymentMethod($tierKey)) {
            $subscription = $this->writeSubscription(
                $billable,
                $ownerKey,
                $tierKey,
                CarbonImmutable::createFromInterface($trialEndsAt),
                $now,
            );

            // This shape has its subscription NOW, so the coupon is spent now. The redirect shape cannot do
            // the same -- see `redeemFor()` for why spending one before the mandate lands would cost the
            // customer their single use on a checkout they may abandon.
            $this->redeemFor($billable, $couponCode, $subscription->id);

            return new SubscriptionStart(SubscriptionState::Trialing);
        }

        // Resolved BEFORE the provider is touched, and the order is the whole reason it is its own statement.
        // As an argument it would be evaluated after `customerFor()`, which WRITES: a misconfigured install
        // would create a customer at the provider and only then refuse. That is not free — the account
        // exists, it is now on somebody's dashboard, and the refusal message says nothing about it.
        $returnUrl = $this->returnUrl();

        $handshake = $this->mandates->beginMandate(
            $this->customers->customerFor($billable),
            $this->verificationAmount($tierKey),
            $returnUrl,
        );

        // Two statements rather than a multi-line ternary inside the array, and the reason is measurable:
        // pcov attributes the arms of one to a single line, so the `null` arm counted as never executed
        // whichever way the branch went. A line that cannot be covered by any test is indistinguishable
        // from one nobody wrote a test for -- and under a 100% floor it is the second reading that gets
        // acted on, by somebody writing a case that was already there.
        $intentTrialEndsAt = null;

        if ($trialEndsAt instanceof DateTimeImmutable) {
            $intentTrialEndsAt = CarbonImmutable::createFromInterface($trialEndsAt);
        }

        SubscriptionIntent::query()->create([
            'owner_type' => $billable->getMorphClass(),
            'owner_id' => $ownerKey,
            'provider' => $this->provider,
            'tier_key' => $tierKey,
            // Carried, not redeemed. The mandate webhook spends it once the subscription is real.
            'coupon_code' => $couponCode,
            'payment_reference' => $handshake->paymentReference,
            'trial_ends_at' => $intentTrialEndsAt,
        ]);

        return new SubscriptionStart(
            SubscriptionState::Activating,
            $handshake->checkoutUrl,
            $handshake->paymentReference,
        );
    }

    /**
     * Whether a subscription started here would actually apply this code.
     *
     * The package's OWN coupon table is the only catalog this driver can act on, because this driver is the
     * one that writes the invoice: the discount arrives as a line the cycle adds, and the cycle reads a
     * redemption. The config-defined coupon map that the hosted checkout resolves against is a different
     * set with a different consumer -- the provider applies those, and here there is no provider to apply
     * anything.
     *
     * Saying so is the whole point. A screen that asked the config resolver under this driver would tell a
     * customer their code took, and then the cycle would find no redemption and bill them in full.
     */
    public function honorsCoupon(string $code): bool
    {
        return $this->honorableCoupon($this->normalizeCode($code)) instanceof Coupon;
    }

    /** Trimmed, and an empty field is the same as no field. */
    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    /**
     * The coupon this code names, if it names one that is still worth carrying.
     *
     * Active and unexpired only -- the same two conditions the cycle re-checks when it applies the
     * discount. Checking them here as well is not duplication for its own sake: it is what lets the screen
     * answer honestly before the customer commits, and the cycle's check is what keeps the answer true
     * months later, which is a different question.
     *
     * The exhaustion cap is deliberately NOT checked here. It is a race by nature -- the last redemption
     * can go between this question and the answer -- so the only place it can be enforced truthfully is
     * inside the redeemer's locked transaction, and reporting it here would be a guess dressed as a fact.
     */
    private function honorableCoupon(?string $code): ?Coupon
    {
        if ($code === null) {
            return null;
        }

        $coupon = Coupon::query()->where('code', $code)->first();

        if (! $coupon instanceof Coupon || ! $coupon->active) {
            return null;
        }

        return $coupon->expires_at !== null && CarbonImmutable::instance($coupon->expires_at)->isPast()
            ? null
            : $coupon;
    }

    /**
     * Spend the coupon for this owner, if there is one to spend.
     *
     * ## A coupon never blocks a subscription
     *
     * `CouponUnavailable` is swallowed on purpose, and the direction matches the hosted checkout's own
     * contract: a code that cannot be spent leaves the customer subscribed at full price, not unsubscribed.
     * The cases it covers are all ones where refusing would be worse than proceeding -- the coupon was
     * exhausted between the screen and here, it expired in the meantime, or this owner already redeemed it.
     *
     * ## Why this is not called before the redirect
     *
     * Redeeming is spending. The (coupon, owner) unique index means an owner gets exactly one redemption
     * per coupon, ever, and `max_redemptions` is decremented globally. Spending either on a checkout the
     * customer may abandon would take something real for something that did not happen -- and this table's
     * whole promise is that an abandoned intent costs nobody anything.
     */
    private function redeemFor(Model $owner, ?string $code, ?int $subscriptionId): void
    {
        $coupon = $this->honorableCoupon($code);

        if (! $coupon instanceof Coupon) {
            return;
        }

        try {
            $this->coupons->redeem($coupon, $owner, $subscriptionId);
        } catch (CouponUnavailable) {
            // Subscribed at full price beats not subscribed. Nothing here is worth failing a sale over.
        }
    }

    /**
     * Whether this billable already has a subscription that would bill alongside a new one.
     *
     * Read on the DEFAULT type and the platform scope, exactly as every other local action reads it — a
     * merchant-scoped row is a different subscription to a different seller and says nothing about whether
     * the platform one exists.
     */
    private function alreadySubscribed(Model $billable): bool
    {
        $existing = Subscription::query()
            ->forOwner($billable)
            ->ofDefaultType()
            ->forMerchant(null)
            ->latest('id')
            ->first();

        if (! $existing instanceof Subscription) {
            return false;
        }

        // The list lives on the MODEL, because the webhook effect asks the same question when the payment
        // settles — days later for a bank transfer — and two copies of it would let one side permit what
        // the other refuses. An ENDED or lapsed row is not one to protect: refusing on it would leave a
        // customer who once canceled permanently unable to come back, the direction a guard must never
        // create.
        return ! $existing->isReplaceableByANewSubscription();
    }

    /**
     * The first payment that establishes the mandate.
     *
     * It appears on the customer's statement, which is why it is configuration and why the default is the
     * smallest unit the plan's currency has rather than the plan price: the point of this payment is to
     * create a mandate, not to collect the first cycle — that is billed by the engine on its own schedule,
     * and taking it here too would charge the customer twice for one period.
     */
    private function verificationAmount(string $tierKey): Money
    {
        $plan = $this->plans->planFor($tierKey);
        $currency = $plan?->amount->currency ?? 'EUR';
        $configured = $this->config->get('billing.mandate_verification_minor', 1);
        $minor = is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 1;

        return new Money($minor, $currency);
    }

    /**
     * Where the provider sends the customer back to.
     *
     * Falls back to the checkout success URL, because that is where a completed purchase already goes and
     * an install that configured one has answered this question. An install that configured neither gets a
     * refusal rather than a payment redirected to nowhere: the customer would complete a real payment and
     * land on an error page, holding a mandate nothing told them about.
     */
    private function returnUrl(): string
    {
        $override = $this->config->get('billing.subscribe_return_url');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        // Through `CheckoutUrls`, not a second reading of the same config. That class answers this exact
        // question for every other checkout in the package, and it does one thing this method did not: when
        // `checkout.success_url` is unset it falls back to the account hub's own checkout-return ROUTE.
        //
        // Which is the shipped state. The configuration file documents it two blocks above this key — "leave
        // these unset and they default to the account hub's own routes" — and ships `success_url` as a bare
        // `env()` with no default. Re-deriving it here read only the config, found nothing, and threw: on a
        // stock install the Subscribe button was not degraded, it was IMPOSSIBLE, and the refusal named a
        // configuration the package's own documentation says is optional.
        //
        // The throw stays reachable, from `successUrl()` itself, for the one install where it is honest:
        // no configured URL AND no account hub to fall back to.
        try {
            return $this->urls->successUrl();
        } catch (RuntimeException) {
            throw SubscriptionNotPermitted::noReturnUrl();
        }
    }

    private function writeSubscription(
        Model $billable,
        int|string $ownerKey,
        string $tierKey,
        CarbonImmutable $trialEndsAt,
        CarbonImmutable $now,
    ): Subscription {
        // updateOrCreate on the UNIQUE KEY, for the same reason the webhook path does it: a subscription is
        // unique on (owner, type, merchant_uid), and an ENDED row still holds that slot. This method is
        // reached only after `alreadySubscribed()` deliberately let such an owner through, so a plain
        // insert would meet the constraint every time somebody came back — and arrive at them as a raw
        // database error on the subscribe button rather than one of the refusals this flow states.
        return Subscription::query()->updateOrCreate(
            [
                'owner_type' => $billable->getMorphClass(),
                'owner_id' => $ownerKey,
                'type' => Subscription::TYPE_DEFAULT,
                'merchant_uid' => MerchantScope::platform()->uid(),
            ],
            [
                'provider' => $this->provider,
                'status' => SubscriptionState::Trialing->value,
                'tier_key' => $tierKey,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $now,
                'current_period_end' => $trialEndsAt,
                // The first cycle runs when the trial ends, not before. A trial that scheduled processing
                // at its start would bill on day one, which is the one thing a trial promises it will not do.
                'scheduled_processing_at' => $trialEndsAt,
                // The previous life, cleared. A returning customer whose `ends_at` survived would be
                // trialing and ending at once, and the presenter reads that as a grace period.
                'ends_at' => null,
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
                'terminated_at' => null,
                'scheduled_tier_key' => null,
                'scheduled_swap_at' => null,
            ],
        );
    }

    /**
     * The billable's primary key, as something a column can hold.
     *
     * A host application's key is whatever its model says it is. Anything that is not already an int or a
     * string is refused rather than coerced: a key this package cannot name is a key it cannot look an
     * owner up by later, and writing a coerced one would produce an intent that resolves to nobody.
     */
    private function ownerKey(Model $billable): int|string
    {
        $key = $billable->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw SubscriptionNotPermitted::unusableOwnerKey($billable->getMorphClass());
        }

        return $key;
    }
}
