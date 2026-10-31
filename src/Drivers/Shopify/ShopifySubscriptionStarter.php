<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\SubscriptionStart;

/**
 * Begins a subscription the way {@see ShopifyCheckout} opens one: at Shopify, approved by the merchant on the
 * confirmation page. Until Shopify reports it, it is activating.
 *
 * Shopify discounts a subscription only as part of its price, so no coupon code is honored.
 */
final readonly class ShopifySubscriptionStarter implements StartsSubscriptions
{
    /** The checkout the driver binds, {@see ShopifyCheckout}, asked through its contract as the screens ask it. */
    public function __construct(private Checkout $checkout) {}

    public function start(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?BillingInterval $interval = null): SubscriptionStart
    {
        $intent = $this->checkout->subscribe($billable, $tierKey, $couponCode, $declarationReference, interval: $interval);
        $id = $intent->payload['subscription_id'] ?? null;

        return new SubscriptionStart(
            SubscriptionState::Activating,
            SafeExternalUrl::orNull($intent->payload['checkout_url'] ?? null),
            is_string($id) ? $id : null,
        );
    }

    public function honorsCoupon(string $code): bool
    {
        return false;
    }
}
