<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mollie\Api\MollieApiClient;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\Money;
use Throwable;

/**
 * A one-time add-on purchase on Mollie: a `oneoff` payment on Mollie's hosted checkout, for the price the catalog
 * names, and a local order that becomes the invoice once Mollie confirms the payment.
 *
 * Mollie issues no invoice of its own, so the order is what the invoice is raised from, as for a cycle this engine
 * bills. It is written before the payment exists and carries the payment's id afterwards; the webhook finds it by
 * that id. The price comes from the catalog key and never from the client, the same rule the Stripe checkout keeps.
 *
 * The add-on key rides on the payment as metadata, under {@see self::ADDON_KEY}. The webhook mapper reads it off the
 * confirmed payment and reports the purchase, so the credit, the grant and the document all hang off Mollie's own
 * confirmation rather than off the redirect, which a buyer can abandon or repeat.
 *
 * Mollie's checkout asks for no tax ID, so `$collectTaxId` has nothing to switch. The invoice takes the ID the
 * package holds for the owner, as the cycle's invoice does, and without one no reverse charge applies.
 */
final readonly class MollieOneTimeCharge implements OneTimeCharge
{
    /** The metadata key the add-on travels under, read back by {@see MollieWebhookEventMapper}. */
    public const string ADDON_KEY = 'addon_key';

    private const string PROVIDER = 'mollie';

    public function __construct(
        private MollieApiClient $client,
        private AddonCatalog $addons,
        private MollieCustomers $customers,
        private CanTransactMoney $eligibility,
        private MarketplaceSaleContext $context,
        private CheckoutUrls $urls,
        private string $webhookUrl,
    ) {}

    public function purchase(Model $billable, string $addonKey, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null, ?string $callerReference = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $this->context->assertMarketOpen($buyerCountry);

        $price = $this->addons->priceFor($addonKey);

        if (! $price instanceof Money || ! $price->isPositive()) {
            throw new InvalidArgumentException("Add-on '{$addonKey}' is not purchasable (no price configured).");
        }

        // Where the buyer comes back to, the same answer the Stripe checkout of an add-on gets. Resolved before anything
        // is written, here or at Mollie: `customerFor()` can create a customer at the provider and `openOrder()` writes
        // the order, and an install with nowhere to return to would keep both behind its refusal.
        $returnUrl = $this->urls->purchaseReturnUrl();

        $customer = $this->customers->customerFor($billable);
        $order = $this->openOrder($billable, $addonKey, $price);

        // Keyed by the order, so a retried request for this purchase reaches the payment the first one created. Set
        // right before the call, because the client clears the key after every request.
        $this->client->setIdempotencyKey('addon-order:'.$order->id);

        try {
            $payment = $this->client->payments->create([
                'description' => $this->addons->label($addonKey),
                'amount' => ['currency' => $price->currency, 'value' => $price->toDecimal()],
                'customerId' => $customer,
                'sequenceType' => 'oneoff',
                'redirectUrl' => $returnUrl,
                'webhookUrl' => $this->webhookUrl,
                'metadata' => array_filter([
                    self::ADDON_KEY => $addonKey,
                    'order' => (string) $order->id,
                    'withdrawal_declaration' => $declarationReference,
                    'caller_reference' => $callerReference,
                ], static fn (?string $value): bool => $value !== null && $value !== ''),
            ]);
        } catch (Throwable $refused) {
            // No payment came back for the order to point at, so nothing can ever confirm it. Closed as failed, it
            // does not read as a purchase still waiting for its money.
            $order->update(['status' => OrderStatus::Failed]);

            throw $refused;
        } finally {
            $this->client->resetIdempotencyKey();
        }

        $order->update(['status' => OrderStatus::Processing, 'payment_reference' => (string) $payment->id]);

        return new ClientIntent(
            driver: self::PROVIDER,
            payload: ['checkout_url' => $payment->getCheckoutUrl() ?? '', 'payment_id' => (string) $payment->id],
            offSessionCapable: false,
        );
    }

    /**
     * Refused, because a tip is a routed sale and Mollie routes none.
     *
     * The marketplace is locked on this driver, and a tip without a merchant to reach is the platform keeping a
     * buyer's money meant for somebody else. The eligibility gate still answers first, as at every entry to a
     * payment, so a buyer who may not pay at all is told that rather than something about routing.
     */
    public function tip(Model $billable, Money $chosen, TaxArchetype $soldAlongside, ?string $declarationReference = null, ?string $buyerCountry = null): ClientIntent
    {
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        throw MarketplaceUnsupported::driverCannotRoute(self::PROVIDER);
    }

    /** The order the invoice is raised from, written with its one add-on line before the payment exists. */
    private function openOrder(Model $billable, string $addonKey, Money $price): Order
    {
        return DB::transaction(function () use ($billable, $addonKey, $price): Order {
            $order = Order::model()::query()->create([
                'owner_type' => $billable->getMorphClass(),
                'owner_id' => $billable->getKey(),
                'provider' => self::PROVIDER,
                'total_minor' => $price->minorUnits,
                'currency' => $price->currency,
                'status' => OrderStatus::Open,
            ]);

            OrderItem::model()::query()->create([
                'order_id' => $order->id,
                'description' => $this->addons->label($addonKey),
                'unit_price_minor' => $price->minorUnits,
                'quantity' => 1,
                'total_minor' => $price->minorUnits,
                'currency' => $price->currency,
                'type' => OrderItemType::Addon,
                'metadata' => ['addon_key' => $addonKey],
            ]);

            return $order;
        });
    }
}
