<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\Discount;
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
    ) {}

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null): ClientIntent
    {
        // Defence in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard (mirrors StripeOneTimeCharge).
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $price = $this->plans->providerPriceFor($tierKey);

        if ($price === null) {
            throw new InvalidArgumentException("Tier '{$tierKey}' has no provider price to subscribe to.");
        }

        $customerId = $this->customers->resolve($billable);

        // The Stripe SDK's generated param shape cannot express a payload assembled at runtime (optional
        // trial/tax/promo/discount groups, a variable line-item list). The payload IS a valid
        // subscription-mode Checkout Session request; its shape is asserted field-by-field in StripeCheckoutTest.
        // @phpstan-ignore argument.type
        $session = $this->stripe->checkout->sessions->create($this->payload($billable, $tierKey, $price, $customerId, $couponCode));

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
    private function payload(Model $billable, string $tierKey, string $price, string $customerId, ?string $couponCode): array
    {
        $payload = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => $this->lineItems($tierKey, $price),
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

        if ($this->providerTax()) {
            // Stripe Tax computes VAT on its own invoice. With an existing customer, automatic_tax
            // requires permission to save the address it collects, or Stripe rejects the session.
            $payload['automatic_tax'] = ['enabled' => true];
            $payload['tax_id_collection'] = ['enabled' => true];
            $payload['customer_update'] = ['address' => 'auto'];
        }

        return $payload;
    }

    /**
     * The tier's base line item plus one line item per billable metered component. A metered price is
     * usage-billed, so it must be sent WITHOUT a quantity.
     *
     * @return list<array<string, mixed>>
     */
    private function lineItems(string $tierKey, string $price): array
    {
        $items = [['price' => $price, 'quantity' => 1]];

        foreach ($this->meters->forTier($tierKey) as $component) {
            if ($component->isBillable()) {
                $items[] = ['price' => $component->providerPrice];
            }
        }

        return $items;
    }

    /**
     * The Stripe coupon a package coupon CODE maps to, or null when the code is empty, invalid/expired, or
     * has no Stripe mapping. resolve() validates the code against the package catalog first, so only a real
     * coupon can apply a discount; the config `billing.coupons.<code>.stripe_coupon` is the passthrough id.
     */
    private function stripeCouponFor(?string $couponCode): ?string
    {
        if ($couponCode === null || $couponCode === '') {
            return null;
        }

        // A code that does not resolve (unknown or expired) is ignored — a bad code never blocks checkout.
        if (! $this->discounts->resolve($couponCode) instanceof Discount) {
            return null;
        }

        // Read by the LITERAL code, never a dotted config path: a code is matched exactly and never split
        // on a dot (the same rule the ConfigDiscountResolver follows).
        $coupons = $this->config->get('billing.coupons');
        $coupon = is_array($coupons) ? ($coupons[$couponCode] ?? null) : null;
        $stripeCoupon = is_array($coupon) ? ($coupon['stripe_coupon'] ?? null) : null;

        return is_string($stripeCoupon) && $stripeCoupon !== '' ? $stripeCoupon : null;
    }

    /** Whether the active tax mode defers to the provider (Stripe Tax), which drives automatic_tax. */
    private function providerTax(): bool
    {
        return $this->config->get('billing.tax') === 'provider';
    }
}
