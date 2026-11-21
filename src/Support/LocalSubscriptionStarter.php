<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pushery\Billing\Consumer\CustomerTypes;
use Pushery\Billing\Contracts\EnsuresProviderCustomer;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\StartsOverAMandate;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Discounts\CouponCodes;
use Pushery\Billing\Discounts\CouponRedeemer;
use Pushery\Billing\Discounts\TrialCodes;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CollectionTiming;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\CouponUnavailable;
use Pushery\Billing\Exceptions\SubscriptionNotPermitted;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\CouponRedemption;
use Pushery\Billing\Models\PaymentMandate;
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
 * A **generic trial** unlocks a tier on the owner and creates no subscription at all — there is nothing to
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
 * Unless the application says the customer chose a mandate they have already given: `startOverTheMandate()` writes the
 * row at once, in the subscription trial or active, and the engine charges that mandate at the first run.
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
final readonly class LocalSubscriptionStarter implements StartsOverAMandate, StartsSubscriptions
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

    public function start(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?BillingInterval $interval = null): SubscriptionStart
    {
        $ownerKey = $this->admit($billable, $tierKey, $interval);
        $couponCode = $this->normalizeCode($couponCode);
        $now = CarbonImmutable::now();

        // A generic trial is checked first because it is the only shape that is not a subscription. Asking
        // about the subscription trial first would attach one on top of it, which is the double trial the
        // policy's own mode derivation exists to prevent.
        //
        // Only for somebody who has not had one. `Trials::grant()` is idempotent for a trial that is still
        // running — it hands the existing end back — and it is not idempotent for one that has lapsed: it
        // writes a fresh end. Called from a buy button that turns Subscribe into an unlimited renewal:
        // press it again after the fourteen days and get fourteen more, as often as you like. Nothing looks
        // wrong, because every call reports success and the customer never pays.
        //
        // The column is checked directly rather than through `onGenericTrial()`, which answers "is one
        // running" and is false for exactly the lapsed case this has to catch.
        //
        // Nor for somebody who had a subscription trial. That one stands on the subscription row and survives
        // the row being reused, and where a tier's own mode is generic, a generic trial after it would be a
        // second free trial under another name.
        if ($this->policy->mode($tierKey) === TrialMode::Generic
            && $billable->getAttribute('trial_ends_at') === null
            && ! Subscription::ownerHasHadATrial($billable)) {
            $granted = $this->genericTrials->grant($billable);

            if ($granted instanceof CarbonInterface) {
                return new SubscriptionStart(SubscriptionState::GenericTrial);
            }

            // A generic mode with a zero length grants nothing. Falling through rather than returning a
            // trial state nobody is on: the caller would show a trial that does not exist, and the customer
            // would find out when access did not arrive.
        }

        $trialEndsAt = $this->subscriptionTrialEnd($billable, $tierKey, $now);

        if (! $this->policy->requiresPaymentMethod($tierKey)) {
            // One transaction for the code and the row, so a code redeemed for days of trial is never left without
            // the subscription it gave them to. A subscription that needs a mandate takes the code when the mandate
            // settles.
            $couponModel = Coupon::model();
            $started = new $couponModel()->getConnection()->transaction(function () use ($billable, $ownerKey, $tierKey, $couponCode, $trialEndsAt, $now, $declarationReference): ?SubscriptionStart {
                [$endsAt, $granted] = $this->trialWithTheCode($billable, $couponCode, $tierKey, $trialEndsAt, $now);

                // Without a trial this shape has nothing to write: the customer is sent for a mandate below.
                if (! $endsAt instanceof CarbonImmutable) {
                    return null;
                }

                return $this->startHere($billable, $ownerKey, $tierKey, $couponCode, $endsAt, $granted, $now, $declarationReference);
            });

            if ($started instanceof SubscriptionStart) {
                return $started;
            }
        }

        // Resolved before the provider is touched, and the order is the whole reason it is its own statement.
        // As an argument it would be evaluated after `customerFor()`, which writes: a misconfigured install
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

        SubscriptionIntent::model()::query()->create([
            'owner_type' => $billable->getMorphClass(),
            'owner_id' => $ownerKey,
            'provider' => $this->provider,
            'tier_key' => $tierKey,
            // Carried, not redeemed. The mandate webhook spends it once the subscription is real.
            'coupon_code' => $couponCode,
            'payment_reference' => $handshake->paymentReference,
            // Carried like the coupon, and for the same reason: the subscription becomes real on the mandate
            // webhook, and that is where the row that has to hold it is written.
            'declaration_reference' => $declarationReference,
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
     * The package's own coupon table is the only catalog this driver can act on, because this driver is the
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

    /**
     * Start the subscription on the owner's default mandate, written at once and charged to that mandate by the engine.
     *
     * The mandate is asked for before anything is written, and its absence is a refusal rather than a redirect: the
     * application called this because the customer chose the mandate they gave, and sending them to the provider would
     * ask for one they believe they gave already. A trial is the subscription trial the policy gives, once per owner; a
     * generic trial stands on the owner rather than on a subscription, and only `start()` grants it.
     */
    public function startOverTheMandate(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?BillingInterval $interval = null): SubscriptionStart
    {
        $ownerKey = $this->admit($billable, $tierKey, $interval);

        if (! PaymentMandate::defaultFor($billable->getMorphClass(), $ownerKey, $this->provider) instanceof PaymentMandate) {
            throw SubscriptionNotPermitted::noMandate($billable->getMorphClass().'#'.$ownerKey, $this->provider);
        }

        $couponCode = $this->normalizeCode($couponCode);
        $now = CarbonImmutable::now();
        $trialEndsAt = $this->subscriptionTrialEnd($billable, $tierKey, $now);
        $couponModel = Coupon::model();

        return new $couponModel()->getConnection()->transaction(function () use ($billable, $ownerKey, $tierKey, $couponCode, $trialEndsAt, $now, $declarationReference): SubscriptionStart {
            [$endsAt, $granted] = $this->trialWithTheCode($billable, $couponCode, $tierKey, $trialEndsAt, $now);

            return $this->startHere($billable, $ownerKey, $tierKey, $couponCode, $endsAt, $granted, $now, $declarationReference);
        });
    }

    /**
     * Refuse what no start may begin, before anything is written or sent, and return the owner's key.
     *
     * The local engine bills a tier at its default interval; it keeps no interval on the subscription to bill another.
     */
    private function admit(Model $billable, string $tierKey, ?BillingInterval $interval): int|string
    {
        if ($interval instanceof BillingInterval && $interval !== $this->plans->planFor($tierKey)?->interval) {
            throw new InvalidArgumentException("The local billing engine bills tier '{$tierKey}' at its default interval, not at {$interval->value}.");
        }

        // The key has to be a key of the tier map, not a path into it. The catalog resolves
        // `billing.tiers.{$key}` through dot notation, so `pro.price_display` reaches a node that is an
        // array — and `find()` therefore says yes to a tier that does not exist. The subscription is then
        // written against it: Active in every report, never billable, sitting permanently due and writing a
        // warning on each scheduled run.
        //
        // Checked against the catalog's own key set rather than by rejecting dots, because the rule is not
        // "no dots" — it is "one of the keys this install actually sells", and the browser is what supplies
        // the value.
        // One check, not two. `find()` was asked first and its null was treated as the refusal — which is
        // wrong twice over: it resolves `billing.tiers.{$key}` through dot notation, so `pro.price_display`
        // reaches a node that happens to be an array and passes, and it is redundant once the key set is
        // consulted, because both read the same configuration. A guard no run can enter is a guard nobody
        // can trust, and the coverage floor is what says so.
        // First, because everything after it reads the key. Left where it naturally belongs — beside the
        // write that uses it — the guard could never fire: `alreadySubscribed()` passes the key straight
        // into a WHERE, so an exotic one dies inside the query builder several steps earlier, with a
        // message about SQL rather than about the billable. Hoisted, it is both reachable and useful.
        $ownerKey = $this->ownerKey($billable);

        if (! array_key_exists($tierKey, $this->tiers->all())) {
            throw SubscriptionNotPermitted::unknownTier($tierKey);
        }

        if ($this->tiers->isUntouchable($tierKey)) {
            throw SubscriptionNotPermitted::untouchableTier($tierKey);
        }

        if ($this->alreadySubscribed($billable)) {
            throw SubscriptionNotPermitted::alreadySubscribed($billable->getMorphClass().'#'.$ownerKey);
        }

        return $ownerKey;
    }

    /**
     * The end of the subscription trial this owner gets on this tier, or null where they get none.
     *
     * Once per owner, the same rule `start()` states for the generic trial — and it was missing on the shape that
     * costs money. `subscriptionTrialEnabled()` and `endsAt()` are computed from config and know nothing about
     * history, so every call handed out a fresh trial. Nothing refused a second one: `alreadySubscribed()`
     * deliberately lets an owner whose row is `ended` or `incomplete_expired` come back, and both are one button
     * away — `cancelNow()` writes `ended` immediately, and a lapsed card-less trial now writes `incomplete_expired`.
     * So: take the free trial, cancel, subscribe again, take it again, as often as you like, never paying.
     *
     * Read from the subscription row rather than a counter, because there is exactly one row per (owner, type,
     * merchant_uid) — every writer here upserts on that key — and its `trial_ends_at` survives the row being reused.
     * A trial that was had is a trial that shows.
     */
    private function subscriptionTrialEnd(Model $billable, string $tierKey, CarbonImmutable $now): ?DateTimeImmutable
    {
        return $this->policy->subscriptionTrialEnabled($tierKey) && ! Subscription::ownerHasHadATrial($billable)
            ? $this->policy->endsAt($now, $tierKey)
            : null;
    }

    /**
     * The trial a subscription written here runs, lengthened by a code that gives the tier more days, with the
     * redemption that gave them. Called inside the transaction that writes the row.
     *
     * A code that gives the tier days counts them from now and is spent only when it lengthens the trial the policy
     * gives; it is redeemed here, under the coupon's row lock (see TrialCodes).
     *
     * @return array{?CarbonImmutable, array{days: int, redemption: CouponRedemption}|null}
     */
    private function trialWithTheCode(Model $billable, ?string $couponCode, string $tierKey, ?DateTimeImmutable $trialEndsAt, CarbonImmutable $now): array
    {
        $codes = new TrialCodes($this->coupons);
        $days = $codes->daysFor($couponCode, $tierKey);
        $endsAt = $trialEndsAt instanceof DateTimeImmutable ? CarbonImmutable::createFromInterface($trialEndsAt) : null;
        $granted = null;

        if ($days !== null && (! $endsAt instanceof CarbonImmutable || $now->addDays($days) > $endsAt)) {
            $granted = $codes->redeemFor($billable, $couponCode, $tierKey);
            $codeEndsAt = $granted === null ? null : $now->addDays($granted['days']);
            $endsAt = $codeEndsAt instanceof CarbonImmutable && (! $endsAt instanceof CarbonImmutable || $codeEndsAt > $endsAt) ? $codeEndsAt : $endsAt;
        }

        return [$endsAt, $granted];
    }

    /**
     * Write the subscription and spend the code on it: the redemption that gave its trial days, or the coupon the code
     * names, which a customer who subscribes here gets at once.
     *
     * @param  array{days: int, redemption: CouponRedemption}|null  $granted
     */
    private function startHere(Model $billable, int|string $ownerKey, string $tierKey, ?string $couponCode, ?CarbonImmutable $trialEndsAt, ?array $granted, CarbonImmutable $now, ?string $declarationReference): SubscriptionStart
    {
        $subscription = $this->writeSubscription($billable, $ownerKey, $tierKey, $trialEndsAt, $now, $declarationReference);
        $started = new SubscriptionStart($trialEndsAt instanceof CarbonImmutable ? SubscriptionState::Trialing : SubscriptionState::Active);

        if ($granted !== null) {
            $granted['redemption']->forceFill(['subscription_id' => $subscription->id])->save();

            return $started;
        }

        // This shape has its subscription now, so the coupon is spent now. The redirect shape cannot do the same --
        // see `redeemFor()` for why spending one before the mandate lands would cost the customer their single use
        // on a checkout they may abandon.
        $this->redeemFor($billable, $couponCode, $subscription->id);

        return $started;
    }

    /** Trimmed of whitespace, invisible characters included, and an empty field is the same as no field. */
    private function normalizeCode(?string $code): ?string
    {
        $code = Str::trim((string) $code);

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
     * The exhaustion cap is deliberately not checked here. It is a race by nature -- the last redemption
     * can go between this question and the answer -- so the only place it can be enforced truthfully is
     * inside the redeemer's locked transaction, and reporting it here would be a guess dressed as a fact.
     */
    private function honorableCoupon(?string $code): ?Coupon
    {
        if ($code === null) {
            return null;
        }

        // Scoped to the issuer. This lane starts platform subscriptions (every row it writes carries the
        // platform sentinel), so the explicit scope is the same query it always ran — written out rather
        // than implied, because an unscoped read is what lets one seller's code discount another's sale the
        // day this lane learns about merchants.
        $coupon = CouponCodes::find(Coupon::model()::query()->issuedBy(MerchantScope::platform()), $code);

        // And a discount at all, by the rule the cycle applies: a row it bills at the full price is not a code that
        // took, so the screen says so and nothing is redeemed for it.
        return $coupon instanceof Coupon && $coupon->isLive() && $coupon->describesADiscount() ? $coupon : null;
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
        $existing = Subscription::model()::query()
            ->forOwner($billable)
            ->ofDefaultType()
            ->forMerchant(null)
            ->latest('id')
            ->first();

        if (! $existing instanceof Subscription) {
            return false;
        }

        // The list lives on the model, because the webhook effect asks the same question when the payment
        // settles — days later for a bank transfer — and two copies of it would let one side permit what
        // the other refuses. An ended or lapsed row is not one to protect: refusing on it would leave a
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
        // `checkout.success_url` is unset it falls back to the account hub's own checkout-return route.
        //
        // Which is the shipped state. The configuration file documents it two blocks above this key — "leave
        // these unset and they default to the account hub's own routes" — and ships `success_url` as a bare
        // `env()` with no default. Re-deriving it here read only the config, found nothing, and threw: on a
        // stock install the Subscribe button was not degraded, it was impossible, and the refusal named a
        // configuration the package's own documentation says is optional.
        //
        // The throw stays reachable, from `successUrl()` itself, for the one install where it is honest:
        // no configured URL and no account hub to fall back to.
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
        ?CarbonImmutable $trialEndsAt,
        CarbonImmutable $now,
        ?string $declarationReference,
    ): Subscription {
        // Without a trial the period starts now, as the mandate webhook starts it, and the timing decides the first run:
        // collected in advance, the next run charges the period the customer has from this moment; in arrears, its end.
        $timing = CollectionTiming::fromConfigured($this->config->get(CollectionTiming::CONFIG_KEY));
        $periodEnd = $trialEndsAt ?? CarbonImmutable::createFromInterface(new TierInterval($this->config)->for($tierKey)->advance($now));
        $firstRun = $timing === CollectionTiming::InAdvance && ! $trialEndsAt instanceof CarbonImmutable ? $now : $periodEnd;

        // updateOrCreate on the UNIQUE KEY, for the same reason the webhook path does it: a subscription is
        // unique on (owner, type, merchant_uid), and an ended row still holds that slot. This method is
        // reached only after `alreadySubscribed()` deliberately let such an owner through, so a plain
        // insert would meet the constraint every time somebody came back — and arrive at them as a raw
        // database error on the subscribe button rather than one of the refusals this flow states.
        return UniqueRow::updateOrCreate(
            Subscription::model()::query(),
            [
                'owner_type' => $billable->getMorphClass(),
                'owner_id' => $ownerKey,
                'type' => Subscription::TYPE_DEFAULT,
                'merchant_uid' => MerchantScope::platform()->uid(),
            ],
            [
                'provider' => $this->provider,
                'status' => ($trialEndsAt instanceof CarbonImmutable ? SubscriptionState::Trialing : SubscriptionState::Active)->value,
                'tier_key' => $tierKey,
                // Kept where this subscription has no trial of its own: `ownerHasHadATrial()` reads it from the row a
                // returning customer reuses, and a null would hand them a fresh trial the next time.
                'trial_ends_at' => $trialEndsAt ?? $this->pastTrialEnd($billable),
                // Whether the customer buys as a consumer or as a business, frozen as the subscription begins, and the
                // moment it began under terms that name the monthly price a year goes on at.
                'customer_type' => Container::getInstance()->make(CustomerTypes::class)->of($billable),
                'monthly_terms_at' => $now,
                // A new contract, whatever the row held before: its withdrawal window opens now.
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                // The first cycle runs when the trial ends, not before. A trial that scheduled processing
                // at its start would bill on day one, which is the one thing a trial promises it will not do.
                'scheduled_processing_at' => $firstRun,
                // The timing configured now, recorded for the life of this subscription. Collected in advance, the run
                // at the trial's end charges the first paid period; in arrears, it closes the free one.
                'collection_timing' => $timing,
                // The previous life, cleared. A returning customer whose `ends_at` survived would be
                // trialing and ending at once, and the presenter reads that as a grace period.
                'ends_at' => null,
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
                'terminated_at' => null,
                'scheduled_tier_key' => null,
                'scheduled_swap_at' => null,
                // The seat-days and the tier difference the ended subscription ran up were billed by its last
                // cycle. This one's first cycle counts from its own start, and carrying them over would bill them
                // a second time.
                'seat_quantity_since' => $now,
                'seat_days_accrued' => 0,
                'tier_adjustment_accrued' => 0,
                // This purchase's declarations, or none. A reused row is a new subscription, and the previous
                // one's declarations do not cover it.
                'declaration_reference' => $declarationReference,
            ],
        );
    }

    /** The end of the trial the owner's row already records, or null where the owner has had none. */
    private function pastTrialEnd(Model $billable): ?CarbonInterface
    {
        return Subscription::model()::query()->forOwner($billable)->ofDefaultType()->forMerchant(null)->first()?->trial_ends_at;
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
