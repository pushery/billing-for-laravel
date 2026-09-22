<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\PlatformFeeResolver;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\ReceiveEligibilityDenied;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Marketplace\SellerSaleGate;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Stripe\StripeClient;

/**
 * The Stripe entrance: a hosted Checkout Session in SUBSCRIPTION mode. The client submits a tier KEY,
 * the price is resolved from the plan catalog (anti-price-injection), the customer is resolved-or-created
 * with identity, and the customer redirects to Stripe's hosted page — which is where SCA / 3-D Secure,
 * the card capture, and (when configured) the trial, the provider tax + VAT-id collection, promotion
 * codes and the billing address all live. On return, the success URL reconciles the subscription onto
 * the local row so the customer is never shown "Free" after paying.
 *
 * Subscription-mode payload rules Stripe enforces (and a fake will not): a metered line item carries NO
 * quantity; automatic_tax against an existing customer needs customer_update.address = 'auto'; and
 * invoice_creation / receipt_email are payment-mode only — in subscription mode Stripe raises the
 * subscription invoice itself.
 */
final readonly class StripeCheckout implements Checkout
{
    public function __construct(
        private StripeClient $stripe,
        private PlanCatalog $plans,
        private MeterCatalog $meters,
        private TrialPolicy $trial,
        private Trials $trials,
        private DiscountResolver $discounts,
        private StripeCustomerRegistry $customers,
        private CheckoutUrls $urls,
        private Repository $config,
        private CanTransactMoney $eligibility,
        private MarketplaceSaleContext $context,
        private MerchantCatalog $catalogs,
        private MerchantAccountDirectory $accounts,
        private PlatformFeeResolver $fees,
        private CanReceiveMoney $receiving,
    ) {}

    /**
     * The seller-side gates a sale has to pass, resolved rather than injected.
     *
     * Every other collaborator of this class arrives through the constructor, and this one deliberately does
     * not. The parameter list is held by a ratchet -- a test asserts it does not grow -- and the gates are the
     * same object for every caller, so a parameter would buy nothing but a slot. A caller that needs different
     * gates binds them, which is what the arms for this behavior do.
     */
    private function sellers(): SellerSaleGate
    {
        return Container::getInstance()->make(SellerSaleGate::class);
    }

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $type = null, ?string $callerReference = null): ClientIntent
    {
        // Defense in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard (mirrors StripeOneTimeCharge).
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        // The buyer's country, where the caller already knows it, is checked before the provider is asked for
        // anything. A hosted checkout cannot restrict the address the buyer enters afterwards, so what they enter
        // is checked again once the provider reports where it taxed the sale (ReverseSaleIntoClosedMarket).
        $this->context->assertMarketOpen($buyerCountry);

        // The merchant this sale routes to, or null for a platform sale. Resolved only when the marketplace
        // is on, so a single-seller install never consults the resolver and everything below is unchanged.
        $merchant = $this->context->routedMerchant();

        $price = $this->priceFor($tierKey, $merchant);

        if ($price === null) {
            throw new InvalidArgumentException("Tier '{$tierKey}' has no provider price to subscribe to.");
        }

        $customerId = $this->customers->resolve($billable);

        // The Stripe SDK's generated param shape cannot express a payload assembled at runtime (optional
        // trial/tax/promo/discount groups, a variable line-item list). The payload IS a valid
        // subscription-mode Checkout Session request; its shape is asserted field-by-field in StripeCheckoutTest.
        // @phpstan-ignore argument.type
        $session = $this->stripe->checkout->sessions->create($this->payload($billable, $tierKey, $price, $customerId, $couponCode, $merchant, $declarationReference, $collectTaxId, $type, $callerReference));

        $url = $session->url ?? null;

        return new ClientIntent(
            driver: 'stripe',
            payload: ['checkout_url' => is_string($url) ? $url : '', 'session_id' => $session->id],
            offSessionCapable: false,
        );
    }

    /**
     * The Checkout Session payload. The tier's base price is one line item; each of the tier's billable
     * metered components is another, with NO quantity (Stripe rejects a quantity on a metered price).
     *
     * @return array<string, mixed>
     */
    private function payload(Model $billable, string $tierKey, string $price, string $customerId, ?string $couponCode, ?Model $merchant, ?string $declarationReference = null, ?bool $collectTaxId = null, ?string $type = null, ?string $callerReference = null): array
    {
        $payload = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => $this->lineItems($tierKey, $price, $merchant),
            'billing_address_collection' => 'required',
            'success_url' => $this->urls->successUrl(),
            'cancel_url' => $this->urls->cancelUrl(),
        ];

        // A subscription trial (mode 'subscription') attaches trial_period_days for this tier. A generic
        // trial (mode 'generic', taken before subscribing) does NOT — and neither does a subscription tier
        // for an owner ALREADY mid generic trial, or a mixed config would trial the same owner twice.
        if ($this->trial->subscriptionTrialEnabled($tierKey) && ! $this->trials->onGenericTrial($billable)) {
            $payload['subscription_data'] = ['trial_period_days' => $this->trial->days($tierKey)];

            // A trial that does not require a card up front: Stripe collects a payment method only if the
            // trial converts, rather than demanding one before it begins. Default is to require it.
            if (! $this->trial->requiresPaymentMethod($tierKey)) {
                $payload['payment_method_collection'] = 'if_required';
            }
        }

        // A resolved package coupon maps to a Stripe coupon (config stripe_coupon) and is applied as a
        // checkout discount — Stripe owns the money math and the native max_redemptions/redeem_by. Stripe
        // forbids a session that carries BOTH an explicit discount and allow_promotion_codes, so an
        // applied coupon wins over the promotion-code field.
        $stripeCoupon = $this->providerCouponFor($couponCode, $this->scopeOf($merchant));

        if ($stripeCoupon !== null) {
            $payload['discounts'] = [['coupon' => $stripeCoupon]];
        } elseif ($this->config->get('billing.checkout.promotion_codes', true) !== false) {
            $payload['allow_promotion_codes'] = true;
        }

        if ($this->context->providerTax()) {
            // Stripe Tax computes VAT on its own invoice. With an existing customer, automatic_tax
            // requires permission to save the address it collects, or Stripe rejects the session.
            $payload['automatic_tax'] = ['enabled' => true];

            // Asking for a tax ID is a choice, and `billing.checkout.tax_id_collection` makes it one. Stripe checks
            // only the FORMAT of an ID during the session and verifies it afterwards, yet reverse-charges on the
            // format alone, so a consumer who types a well-formed invalid ID buys without VAT the platform still
            // owes. A platform that sells to consumers may leave the field out and charge every buyer their
            // country's tax. On by default, so an existing installation keeps the field.
            //
            // A caller can decide it for THIS checkout, and the installation's setting is only the default. A
            // marketplace selling to consumers and to businesses needs both answers at once: no field where a
            // consumer buys, and the field where a business has to prove its status for the reverse charge.
            if ($collectTaxId ?? $this->config->get('billing.checkout.tax_id_collection', true) !== false) {
                $payload['tax_id_collection'] = ['enabled' => true];
            }

            $payload['customer_update'] = ['address' => 'auto'];
        }

        // WHAT RIDES ON THE SUBSCRIPTION, assembled in ONE place before it is attached.
        //
        // Both of these have to reach the subscription object rather than the session: session metadata stays
        // behind on the session, while `subscription_data.metadata` is copied onto the subscription and
        // repeated on every later webhook about it — which is what the local row mirrors.
        //
        // Collected first and attached once, because the alternative is an ORDER DEPENDENCY nobody can see.
        // Written as two blocks that each assign `metadata`, whichever ran last erases the other, and both
        // losses are silent: a dropped declaration reads as a buyer who declared nothing, and a dropped type
        // sends a second contract into the first contract's row. One assembly cannot have that bug.
        //
        // An empty map attaches nothing at all, so a checkout that names neither sends exactly the payload it
        // sent before either key existed.
        $metadata = [];

        if ($declarationReference !== null) {
            $metadata['withdrawal_declaration'] = $declarationReference;
        }

        // WHICH contract of this owner at this merchant the subscription is — the field that lets a second
        // contract with ONE merchant find its own local row instead of overwriting the first one's.
        if ($type !== null) {
            $metadata['subscription_type'] = $type;
        }

        // THE CALLER'S OWN KEY, carried and never interpreted. A consumer that writes its row BEFORE opening
        // the checkout needs to find that row again on the webhook, and until now the only key that traveled
        // was the withdrawal declaration — which a business buyer does not have, because a business has no
        // right of withdrawal to declare. So exactly the purchases with no declaration had no key at all, and
        // for a subscription there was not even a workaround: the subscription reference does not exist yet
        // when the session is opened.
        //
        // ITS OWN KEY, NEVER `withdrawal_declaration`. Reusing that one would send a correlation id to
        // the provider and read it back with the meaning "this buyer declared", which is the one statement a
        // business checkout must not make. Two keys, two meanings.
        if ($callerReference !== null) {
            $metadata['caller_reference'] = $callerReference;
        }

        if ($metadata !== []) {
            $payload['subscription_data'] = [
                ...($payload['subscription_data'] ?? []),
                'metadata' => $metadata,
            ];
        }

        // A routed sale MERGES its destination and fee into subscription_data rather than assigning it: the
        // trial block above may already have populated it, and overwriting would silently drop the trial. A
        // platform sale adds nothing and its payload is byte-for-byte the single-seller one.
        if ($merchant instanceof Model) {
            $routing = $this->routing($merchant);
            $existing = $payload['subscription_data'] ?? [];

            // The METADATA is merged a level deeper. The separate-transfer lane names its merchant and terms there,
            // and the declaration key above may already sit in the same bag; a plain spread would replace one with
            // the other.
            if (isset($routing['metadata'])) {
                $routing['metadata'] = [...($existing['metadata'] ?? []), ...$routing['metadata']];
            }

            $payload['subscription_data'] = [...$existing, ...$routing];
        }

        return $payload;
    }

    /**
     * The tier's base line item plus one line item per billable metered component. A metered price is
     * usage-billed, so it must be sent WITHOUT a quantity.
     *
     * @return list<array<string, mixed>>
     */
    private function lineItems(string $tierKey, string $price, ?Model $merchant): array
    {
        $items = [['price' => $price, 'quantity' => 1]];

        // A routed sale carries only the merchant's base tier price. Metered components are platform-catalog
        // concepts — MerchantCatalog scopes a merchant's tiers and plans, not their meters — so adding them
        // to a creator's subscription would bill the platform's meters against the creator's sale. Merchant-
        // scoped metering is a separate capability; until it exists a routed tier is flat.
        if ($merchant instanceof Model) {
            return $items;
        }

        foreach ($this->meters->forTier($tierKey) as $component) {
            if ($component->isBillable()) {
                $items[] = ['price' => $component->providerPrice];
            }
        }

        return $items;
    }

    /** The scope of a sale to this merchant — the platform when there is none. */
    private function scopeOf(?Model $merchant): MerchantScope
    {
        return $merchant instanceof Model ? MerchantScope::forMerchant($merchant) : MerchantScope::platform();
    }

    /**
     * The Stripe coupon a package coupon CODE maps to on a sale by this seller, or null when the code is
     * empty, does not resolve for that seller, or has no Stripe mapping.
     *
     * TWO sources, the row before the config. `billing_coupons.provider_coupon_id` describes the coupon it
     * sits on; `billing.coupons.<code>.stripe_coupon` is a global map needing an entry per code. Until this
     * read existed the column had no reader anywhere in the package: an adopter who filled it — because the
     * model and the migration offer it — got a discount that never applied, and nothing threw or warned.
     * That is the whole defect, and it is invisible from inside the application.
     *
     * A config-only installation is unchanged. No row means the config answers, exactly as before.
     *
     * Public, and the visibility is the point rather than a convenience: this is also the honest answer to
     * "will this code do anything", which the subscription starter has to give a screen BEFORE the customer
     * commits. Read-only and side-effect free -- it resolves and looks up, it never redeems.
     */
    public function providerCouponFor(?string $couponCode, ?MerchantScope $merchant = null): ?string
    {
        if ($couponCode === null || $couponCode === '') {
            return null;
        }

        // A code that does not resolve for THIS seller (unknown, expired, or another seller's) is ignored —
        // a bad code never blocks checkout.
        //
        // This stays FIRST, ahead of both sources. The column is a MAPPING, not an authority: a row must
        // never apply a discount the catalog rejected, or filling one column would be a way past the
        // validity check rather than a way to reach the provider id. The seller's own live rows are part of
        // that catalog. Before they were, a code that existed only as a row failed right here, so a
        // merchant's coupon could be created and scoped and was never applied.
        if (! $this->discounts->resolve($couponCode, $merchant) instanceof Discount) {
            return null;
        }

        // Matched on the code column, so the literal-code rule below holds here too — a code is never
        // split, and a row is reached only by the exact string the catalog just accepted.
        //
        // Scoped to the SELLER of this sale. Without the scope this finds any issuer's row of that name, so
        // one seller's provider coupon would be applied to another seller's checkout — and on this lane the
        // discount is money Stripe takes off the invoice.
        //
        // And only a LIVE row. A deactivated or expired one is a coupon that was withdrawn, and a config entry of the
        // same code passes the check above on its own account, so a row read without asking would put the withdrawn
        // coupon's discount on the invoice anyway.
        $row = Coupon::model()::query()->issuedBy($merchant)->where('code', $couponCode)->first();
        $onTheRow = $row instanceof Coupon && $row->isLive() ? $row->provider_coupon_id : null;

        if (is_string($onTheRow) && $onTheRow !== '') {
            return $onTheRow;
        }

        // Read by the LITERAL code, never a dotted config path: a code is matched exactly and never split
        // on a dot (the same rule the ConfigDiscountResolver follows).
        //
        // NOT scoped, and on purpose. The config map carries no issuer, so a code declared there is the
        // platform's and its Stripe coupon applies on every sale, a merchant's included — the same reach the
        // resolver gives it. A code only one seller should honor belongs in a row that seller issued.
        $coupons = $this->config->get('billing.coupons');
        $coupon = is_array($coupons) ? ($coupons[$couponCode] ?? null) : null;
        $stripeCoupon = is_array($coupon) ? ($coupon['stripe_coupon'] ?? null) : null;

        return is_string($stripeCoupon) && $stripeCoupon !== '' ? $stripeCoupon : null;
    }

    /**
     * The provider coupon this code maps to on the sale {@see subscribe()} would open right now.
     *
     * The same routed merchant, from the same context, so the answer a screen shows before the customer
     * commits is the one the session applies afterwards. {@see providerCouponFor()} without a scope asks
     * about a platform sale instead, which on a marketplace is a different sale — and the two can disagree
     * about the very same code.
     */
    public function providerCouponForTheSale(?string $couponCode): ?string
    {
        return $this->providerCouponFor($couponCode, $this->scopeOf($this->context->routedMerchant()));
    }

    /**
     * The tier's provider price — from the MERCHANT's own catalog for a routed sale, the platform plan
     * catalog otherwise. The anti-price-injection guarantee holds in both: a tier KEY resolves only to a
     * price the relevant catalog declares, never one the client submitted.
     */
    private function priceFor(string $tierKey, ?Model $merchant): ?string
    {
        if (! $merchant instanceof Model) {
            return $this->plans->providerPriceFor($tierKey);
        }

        return $this->catalogs->planCatalog(MerchantScope::forMerchant($merchant))->providerPriceFor($tierKey);
    }

    /**
     * How a subscription is routed to a merchant, as a subscription_data fragment: a destination and a fee percent,
     * or on the separate-transfer lane the merchant's account and the frozen terms in the subscription's metadata.
     *
     * Refused before any provider call when the merchant cannot receive — unknown counts as no, because the
     * capability is reported asynchronously — or has no account on file to route to. On the destination lane the
     * fee is expressed as application_fee_percent, a PERCENTAGE of each recurring invoice; a flat per-transaction
     * component has no place in a percentage, and dropping it silently would undercharge the agreed commission on
     * every renewal, so a flat component is refused loudly instead of quietly discarded.
     *
     * ## The basis on this lane is the GROSS, and the configured rate is documented as a net rate
     *
     * That difference is stated here because it cannot be fixed here. Stripe defines the field as a
     * percentage of the subscription's invoice TOTAL, and a total includes the buyer's tax — so a net rate
     * is not expressible through it. Converting one (`gross_pct = bps / (1 + t)`) fails at the next step:
     * `t` differs per buyer, while a subscription carries a single percentage for every invoice and every
     * buyer. No one number is right for 19%, 20% and a reverse-charge buyer at once, and the plausible-looking
     * one is the worst outcome — right for whoever happens to match, wrong for everyone else, and drifting
     * further with every market an adopter adds.
     *
     * So on a 119.00 invoice at 19% with a 10% rate this lane takes 11.90, where the routed money path takes
     * 10.00. Which answer the package should settle on is still open; until it is settled, the lane says what
     * it does rather than inheriting a promise it cannot keep. Silence is what made the same divergence
     * expensive once already. The separate-transfer lane splits each cycle on the amount paid as well, so both
     * hosted subscription lanes answer 11.90.
     *
     * @return array{application_fee_percent: float, transfer_data: array{destination: string}}|array{metadata: array<string, string>}
     */
    private function routing(Model $merchant): array
    {
        $chargeType = $this->context->chargeType();

        // The charge type and the seller-of-record posture are independent axes that must agree, and this
        // lane used to assemble the payment without ever asking. The check happens BEFORE anything is
        // assembled, which is the only point at which refusing is still free.
        $this->context->assertRoutingCompatible($chargeType);

        if (! $this->receiving->check($merchant)) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        $account = $this->accounts->accountFor($merchant);

        if (! $account instanceof MerchantAccountReference) {
            throw ReceiveEligibilityDenied::forMerchant();
        }

        // The tax standing, as on the direct payment. The Union gate has nothing to ask: a subscription is its
        // own archetype and never goods.
        $this->sellers()->assertTaxStandingEstablished($merchant);

        $fee = $this->fees->feeFor($merchant);

        // THE CONFIGURED LANE AND THE EMITTED ONE ARE THE SAME STATEMENT, which is the seam this method once got
        // wrong. It used to emit `transfer_data.destination` whatever was configured, so on the shipped defaults a
        // separate transfer passed the posture guard and then went out as the destination charge the table forbids
        // for that posture: the money went straight to the merchant while the documents named the platform as
        // seller. The lane then refused separate transfers outright, because the share moves in a second call a
        // webhook away and nothing made it.
        //
        // Something makes it now. A separate-transfer subscription carries no routing at all: the platform takes
        // each cycle's payment, the merchant's account and the frozen terms ride in the subscription's metadata,
        // and every paid invoice writes its row and moves the share from the charge behind it. A flat fee is fine
        // here, because the package computes each cycle's split itself.
        if ($chargeType === ChargeType::SeparateTransfer) {
            return ['metadata' => StripeSubscriptionRouting::metadata($account, $fee)];
        }

        if ($fee->flatMinor !== 0) {
            throw new InvalidArgumentException(
                'A routed subscription fee must be rate-only: application_fee_percent cannot express a flat fee component.'
            );
        }

        return [
            'application_fee_percent' => $fee->bps / 100,
            'transfer_data' => ['destination' => $account->accountId],
        ];
    }
}
