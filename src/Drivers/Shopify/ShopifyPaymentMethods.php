<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Exceptions\NotPossibleOnShopify;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The payment methods of a merchant on Shopify, which the app does not have.
 *
 * The merchant pays the Shopify invoice with the method they keep in the Shopify admin, so the app lists none,
 * offers no page to add one, and refuses to change one.
 */
final readonly class ShopifyPaymentMethods implements PaymentMethods
{
    public function addMethodUrl(Model $billable): ?string
    {
        return null;
    }

    public function setupIntent(Model $billable): ClientIntent
    {
        throw NotPossibleOnShopify::useThePaymentRails('collect a payment method');
    }

    public function all(Model $billable): array
    {
        return [];
    }

    public function default(Model $billable): ?PaymentMethod
    {
        return null;
    }

    public function setDefault(Model $billable, string $methodId): void
    {
        throw NotPossibleOnShopify::useThePaymentRails('choose a default payment method');
    }

    public function remove(Model $billable, string $methodId): void
    {
        throw NotPossibleOnShopify::useThePaymentRails('remove a payment method');
    }
}
