<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * What the Shopify driver promises.
 *
 * Shopify runs the cycle: it bills the merchant on the Shopify invoice, collects the tax that applies to the
 * charge, and prorates a plan the merchant replaces. Those two are native. Metered usage is not: the driver
 * reports no usage records, so a tier that prices usage is not sold through it. It offers no portal an app could
 * open, keeps no credit balance of the app's, and the app never sees a payment method, so both method lists are
 * empty and every screen that shows payment methods shows none.
 */
final readonly class ShopifyCapabilities
{
    public static function make(): DriverCapabilities
    {
        return new DriverCapabilities(
            supportsHostedPortal: false,
            supportsProviderTax: true,
            supportsMeteredNative: false,
            supportsProviderProration: true,
            supportsProviderCredit: false,
            availablePaymentMethods: [],
            recurringCapableMethods: [],
            // A plan changes, and a canceled subscription starts again, only through a new subscription the
            // merchant approves.
            supportsInPlaceSubscriptionChanges: false,
        );
    }
}
