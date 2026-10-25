<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Pushery\Billing\ValueObjects\Money;

/**
 * Between the package's minor units and Shopify's `MoneyInput`, a decimal amount with its currency code.
 *
 * The conversion lives on {@see Money}, which works from the currency's minor-unit exponent, so a zero-decimal
 * currency is sent without a point. Only the shape of the request is Shopify's.
 */
final readonly class ShopifyMoney
{
    /** @return array{amount: string, currencyCode: string} */
    public static function input(Money $amount): array
    {
        return ['amount' => $amount->toDecimal(), 'currencyCode' => $amount->currency];
    }
}
