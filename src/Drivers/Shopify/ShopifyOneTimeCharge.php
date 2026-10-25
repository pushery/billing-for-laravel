<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\ShopifyRequestFailed;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Models\ShopifyPurchase;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\Money;

/**
 * An add-on bought through Shopify: a one-time purchase the merchant approves in the Shopify admin.
 *
 * The price is the catalog's, never the caller's. The purchase is created at Shopify as `PENDING` and recorded as
 * a {@see ShopifyPurchase} under its id, with the add-on, the amount, the declarations and the caller's reference,
 * because a Shopify purchase carries nothing of the app's but its name. Once the merchant approves it, Shopify
 * reports it `ACTIVE`, and the webhook turns the record into the add-on purchase every driver reports.
 *
 * Prepaid units that do not expire are sold this way as well: the add-on grants them, and the package keeps them
 * itself, as on every driver. Shopify only collects the purchase.
 *
 * A tip routes money to a merchant, which Shopify's billing cannot do, so it is refused.
 */
final readonly class ShopifyOneTimeCharge implements OneTimeCharge
{
    private const string CREATE = <<<'GRAPHQL'
        mutation BillingPurchaseCreate($name: String!, $price: MoneyInput!, $returnUrl: URL!, $test: Boolean) {
          appPurchaseOneTimeCreate(name: $name, price: $price, returnUrl: $returnUrl, test: $test) {
            appPurchaseOneTime { id }
            confirmationUrl
            userErrors { field message }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyAdmin $admin,
        private ShopifyShops $shops,
        private AddonCatalog $addons,
        private CheckoutUrls $urls,
        private CanTransactMoney $eligibility,
        private MarketplaceSaleContext $context,
        private Repository $config,
    ) {}

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $callerReference = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->context->assertMarketOpen($buyerCountry);

        if ($this->context->routedMerchant() instanceof Model) {
            throw MarketplaceUnsupported::driverCannotRoute('shopify');
        }

        $price = $this->addons->priceFor($addonKey);

        if (! $price instanceof Money || ! $price->isPositive()) {
            throw new InvalidArgumentException("Add-on '{$addonKey}' is not purchasable (no price configured).");
        }

        $shop = $this->shops->of($billable);

        $created = $this->admin->mutate($shop, self::CREATE, [
            'name' => $this->addons->label($addonKey),
            'price' => ShopifyMoney::input($price),
            'returnUrl' => $this->urls->purchaseReturnUrl(),
            'test' => $this->config->get('billing.shopify.test') === true,
        ], 'appPurchaseOneTimeCreate');

        $purchase = $created['appPurchaseOneTime'] ?? null;
        $id = is_array($purchase) ? ($purchase['id'] ?? null) : null;
        $url = SafeExternalUrl::onHosts($created['confirmationUrl'] ?? null, [$shop, 'admin.shopify.com']);

        if (! is_string($id) || $url === null) {
            throw ShopifyRequestFailed::noPayload($shop, 'appPurchaseOneTimeCreate.confirmationUrl');
        }

        ShopifyPurchase::model()::query()->create([
            'owner_type' => $billable->getMorphClass(),
            'owner_id' => $billable->getKey(),
            'purchase_id' => $id,
            'addon_key' => $addonKey,
            'amount_minor' => $price->minorUnits,
            'currency' => $price->currency,
            'declaration_reference' => $declarationReference,
            'caller_reference' => $callerReference,
        ]);

        return new ClientIntent(
            driver: 'shopify',
            payload: ['checkout_url' => $url, 'purchase_id' => $id],
            offSessionCapable: false,
        );
    }

    public function tip(Model $billable, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference = null, ?string $buyerCountry = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        throw MarketplaceUnsupported::driverCannotRoute('shopify');
    }
}
