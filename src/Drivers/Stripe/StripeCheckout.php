<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

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
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\ReceiveEligibilityDenied;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
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

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null): ClientIntent
    {
        // Defense in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard (mirrors StripeOneTimeCharge).
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

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
        $session = $this->stripe->checkout->sessions->create($this->payload($billable, $tierKey, $price, $customerId, $couponCode, $merchant));

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
    private function payload(Model $billable, string $tierKey, string $price, string $customerId, ?string $couponCode, ?Model $merchant): array
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
        $stripeCoupon = $this->stripeCouponFor($couponCode);

        if ($stripeCoupon !== null) {
            $payload['discounts'] = [['coupon' => $stripeCoupon]];
        } elseif ($this->config->get('billing.checkout.promotion_codes', true) !== false) {
            $payload['allow_promotion_codes'] = true;
        }

        if ($this->context->providerTax()) {
            // Stripe Tax computes VAT on its own invoice. With an existing customer, automatic_tax
            // requires permission to save the address it collects, or Stripe rejects the session.
            $payload['automatic_tax'] = ['enabled' => true];
            $payload['tax_id_collection'] = ['enabled' => true];
            $payload['customer_update'] = ['address' => 'auto'];
        }

        // A routed sale MERGES its destination and fee into subscription_data rather than assigning it: the
        // trial block above may already have populated it, and overwriting would silently drop the trial. A
        // platform sale adds nothing and its payload is byte-for-byte the single-seller one.
        if ($merchant instanceof Model) {
            $payload['subscription_data'] = [
                ...($payload['subscription_data'] ?? []),
                ...$this->routing($merchant),
            ];
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

    /**
     * The Stripe coupon a package coupon CODE maps to, or null when the code is empty, invalid/expired, or
     * has no Stripe mapping.
     *
     * TWO sources, the row before the config. `billing_coupons.provider_coupon_id` describes the coupon it
     * sits on; `billing.coupons.<code>.stripe_coupon` is a global map needing an entry per code. Until this
     * read existed the column had no reader anywhere in the package: an adopter who filled it — because the
     * model and the migration offer it — got a discount that never applied, and nothing threw or warned.
     * That is the whole defect, and it is invisible from inside the application.
     *
     * A config-only installation is unchanged. No row means the config answers, exactly as before.
     */
    private function stripeCouponFor(?string $couponCode): ?string
    {
        if ($couponCode === null || $couponCode === '') {
            return null;
        }

        // A code that does not resolve (unknown or expired) is ignored — a bad code never blocks checkout.
        //
        // This stays FIRST, ahead of both sources. The column is a MAPPING, not an authority: a row must
        // never apply a discount the catalog rejected, or filling one column would be a way past the
        // validity check rather than a way to reach the provider id.
        if (! $this->discounts->resolve($couponCode) instanceof Discount) {
            return null;
        }

        // Matched on the code column, so the literal-code rule below holds here too — a code is never
        // split, and a row is reached only by the exact string the catalog just accepted.
        $onTheRow = Coupon::query()->where('code', $couponCode)->value('provider_coupon_id');

        if (is_string($onTheRow) && $onTheRow !== '') {
            return $onTheRow;
        }

        // Read by the LITERAL code, never a dotted config path: a code is matched exactly and never split
        // on a dot (the same rule the ConfigDiscountResolver follows).
        $coupons = $this->config->get('billing.coupons');
        $coupon = is_array($coupons) ? ($coupons[$couponCode] ?? null) : null;
        $stripeCoupon = is_array($coupon) ? ($coupon['stripe_coupon'] ?? null) : null;

        return is_string($stripeCoupon) && $stripeCoupon !== '' ? $stripeCoupon : null;
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
     * The destination and fee that route a subscription to a merchant, as a subscription_data fragment.
     *
     * Refused before any provider call when the merchant cannot receive — unknown counts as no, because the
     * capability is reported asynchronously — or has no account on file to route to. The fee is expressed as
     * application_fee_percent, a PERCENTAGE of each recurring invoice; a flat per-transaction component has no
     * place in a percentage, and dropping it silently would undercharge the agreed commission on every
     * renewal, so a flat component is refused loudly instead of quietly discarded.
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
     * expensive once already.
     *
     * @return array{application_fee_percent: float, transfer_data: array{destination: string}}
     */
    private function routing(Model $merchant): array
    {
        $chargeType = $this->context->chargeType();

        // A hosted session cannot serve a separate transfer, exactly as on the one-time lane: the platform
        // takes the whole payment and the merchant's share moves in a SECOND call, which can only be made
        // once the payment has succeeded — a webhook away, long after this method has returned.
        //
        // This refusal is what closes the seam below. The guard checks the CONFIGURED charge type, but the
        // payload this method assembles is unconditionally a DESTINATION charge (`transfer_data.destination`).
        // On the shipped defaults those two disagree: separate_transfer is permitted for the deemed-supplier
        // posture and passes the guard, while the destination charge it then emits is the one pairing the
        // table forbids for that posture. A guard on the configured half cannot see a broken seam, and the
        // money moves the wrong way in silence — straight to the merchant, while the documents about to be
        // issued name the platform as seller. Refusing here makes the configured type and the emitted one
        // the same statement.
        if ($chargeType === ChargeType::SeparateTransfer) {
            throw MarketplaceUnsupported::separateTransferNeedsRoutedPayment();
        }

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

        $fee = $this->fees->feeFor($merchant);

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
