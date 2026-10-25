<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;

/**
 * The payment that bought a period on Shopify, which the app cannot read.
 *
 * Shopify charges the subscription on the merchant's Shopify invoice and reports no payment of a period to the
 * app, and a refund of the period's rest is Shopify's to make, as a credit when the subscription is canceled with
 * proration. So no payment is known here, and nothing is owed back by the app.
 */
final readonly class ShopifySubscriptionPayments implements ReadsSubscriptionPayments
{
    public function currentPeriodPayment(Subscription $subscription): ?SubscriptionPeriodPayment
    {
        return null;
    }
}
