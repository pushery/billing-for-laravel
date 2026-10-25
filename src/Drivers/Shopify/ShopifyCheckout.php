<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\NotPossibleOnShopify;
use Pushery\Billing\Exceptions\ShopifyRequestFailed;
use Pushery\Billing\Exceptions\SubscriptionNotPermitted;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\SubscriptionIntent;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\Trials\TrialPolicy;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\Money;

/**
 * The Shopify entrance: a subscription the merchant approves in the Shopify admin.
 *
 * The tier key is resolved against the plan catalog, as on every driver, so the price is never the client's.
 * Shopify bills every 30 days or every year, so a tier priced by the month or the year can be sold here and any
 * other interval is refused. The trial is the policy's, granted once per owner.
 *
 * The subscription is created at Shopify as `PENDING` and recorded as a {@see SubscriptionIntent} under its id,
 * with the tier and the declarations the buyer made. The intent is what lets the webhook name the tier again,
 * because a Shopify subscription carries nothing of the app's but a name. The merchant is sent to the
 * confirmation page; once they approve, Shopify reports the subscription `ACTIVE` and returns them to the
 * checkout's return address.
 *
 * Shopify keeps one subscription per installation, so a new one replaces the current one once it is approved.
 * That is how a plan is changed here. A tier that prices usage past its allowance is not sold: the driver reports
 * no usage to Shopify, and the units would be counted and never charged. Units sold in advance are an add-on. A coupon code is not applied, since Shopify discounts a subscription only
 * as part of its price; a code is ignored, as an unknown one is on every driver.
 */
final readonly class ShopifyCheckout implements Checkout
{
    private const string CREATE = <<<'GRAPHQL'
        mutation BillingSubscriptionCreate($name: String!, $returnUrl: URL!, $lineItems: [AppSubscriptionLineItemInput!]!, $trialDays: Int, $test: Boolean) {
          appSubscriptionCreate(name: $name, returnUrl: $returnUrl, lineItems: $lineItems, trialDays: $trialDays, test: $test) {
            appSubscription { id }
            confirmationUrl
            userErrors { field message }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyAdmin $admin,
        private ShopifyShops $shops,
        private TierCatalog $tiers,
        private PlanCatalog $plans,
        private MeterCatalog $meters,
        private TrialPolicy $trial,
        private CheckoutUrls $urls,
        private CanTransactMoney $eligibility,
        private MarketplaceSaleContext $context,
        private Repository $config,
    ) {}

    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $type = null, ?string $callerReference = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->context->assertMarketOpen($buyerCountry);

        // Shopify pays the app's share to the app's partner account and nowhere else.
        if ($this->context->routedMerchant() instanceof Model) {
            throw MarketplaceUnsupported::driverCannotRoute('shopify');
        }

        if ($type !== null && $type !== Subscription::TYPE_DEFAULT) {
            throw NotPossibleOnShopify::holdASecondContract($type);
        }

        if ($callerReference !== null) {
            throw NotPossibleOnShopify::carryACallerReference();
        }

        if (! array_key_exists($tierKey, $this->tiers->all())) {
            throw SubscriptionNotPermitted::unknownTier($tierKey);
        }

        if ($this->tiers->isUntouchable($tierKey)) {
            throw SubscriptionNotPermitted::untouchableTier($tierKey);
        }

        $plan = $this->plans->planFor($tierKey)
            ?? throw new InvalidArgumentException("Tier '{$tierKey}' has no price to subscribe to.");

        // Usage past the allowance would be counted and never charged, so a tier that prices it is not sold here.
        $priced = array_find(
            $this->meters->forTier($tierKey),
            static fn (MeteredComponent $component): bool => $component->unitPrice instanceof Money || $component->providerPrice !== null,
        );

        if ($priced instanceof MeteredComponent) {
            throw NotPossibleOnShopify::billUsage($tierKey, $priced->key);
        }

        $shop = $this->shops->of($billable);
        $trialDays = $this->trialDays($billable, $tierKey);

        $created = $this->admin->mutate($shop, self::CREATE, [
            'name' => $this->tiers->label($tierKey),
            'returnUrl' => $this->urls->successUrl(),
            'lineItems' => [[
                'plan' => ['appRecurringPricingDetails' => [
                    'price' => ShopifyMoney::input($plan->amount),
                    'interval' => $this->interval($plan->interval),
                ]],
            ]],
            'trialDays' => $trialDays,
            'test' => $this->test(),
        ], 'appSubscriptionCreate');

        $subscription = $created['appSubscription'] ?? null;
        $id = is_array($subscription) ? ($subscription['id'] ?? null) : null;
        $url = SafeExternalUrl::onHosts($created['confirmationUrl'] ?? null, [$shop, 'admin.shopify.com']);

        if (! is_string($id) || $url === null) {
            throw ShopifyRequestFailed::noPayload($shop, 'appSubscriptionCreate.confirmationUrl');
        }

        SubscriptionIntent::model()::query()->create([
            'owner_type' => $billable->getMorphClass(),
            'owner_id' => $billable->getKey(),
            'provider' => 'shopify',
            'tier_key' => $tierKey,
            'payment_reference' => $id,
            'declaration_reference' => $declarationReference,
            'trial_ends_at' => $trialDays > 0 ? CarbonImmutable::now()->addDays($trialDays) : null,
        ]);

        return new ClientIntent(
            driver: 'shopify',
            payload: ['checkout_url' => $url, 'subscription_id' => $id],
            offSessionCapable: false,
        );
    }

    /** Shopify's name for the tier's interval. */
    private function interval(BillingInterval $interval): string
    {
        return match ($interval) {
            BillingInterval::Month => 'EVERY_30_DAYS',
            BillingInterval::Year => 'ANNUAL',
            default => throw NotPossibleOnShopify::billEvery($interval->value),
        };
    }

    /** The trial the policy grants for the tier, once per owner. */
    private function trialDays(Model $billable, string $tierKey): int
    {
        if (! $this->trial->subscriptionTrialEnabled($tierKey) || Subscription::ownerHasHadATrial($billable)) {
            return 0;
        }

        return $this->trial->days($tierKey);
    }

    /** Whether Shopify is to treat the charges as test charges, which a development store approves without paying. */
    private function test(): bool
    {
        return $this->config->get('billing.shopify.test') === true;
    }
}
