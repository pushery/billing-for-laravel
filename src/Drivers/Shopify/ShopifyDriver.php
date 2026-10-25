<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * The Shopify driver, for an app the Shopify App Store sells.
 *
 * Shopify requires such an app to bill its merchants through Shopify, and Shopify then runs the cycle: the
 * merchant approves a subscription or a purchase in the Shopify admin, Shopify charges it on the merchant's
 * Shopify invoice, and the app learns the outcome from a webhook. The engine only ends what Shopify does not
 * report, a cancellation's end, and the rails carry nothing, because the app never holds a payment method.
 *
 * It does not implement `RoutesMoney`. Shopify pays the app's share to the app's partner account and nowhere
 * else, so there is nothing a marketplace could route.
 */
final readonly class ShopifyDriver implements BillingDriver
{
    public function __construct(
        private PaymentRails $rails,
        private BillingEngine $engine,
    ) {}

    public function name(): string
    {
        return 'shopify';
    }

    public function rails(): PaymentRails
    {
        return $this->rails;
    }

    public function engine(): BillingEngine
    {
        return $this->engine;
    }

    public function capabilities(): DriverCapabilities
    {
        return ShopifyCapabilities::make();
    }
}
