<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\ValueObjects\Money;

/**
 * An amount as Stripe's API states it, converted at the one place where the driver crosses into Stripe and back.
 *
 * Stripe takes and reports amounts in a currency's smallest unit, the same minor units `Money` holds, with two
 * exceptions it keeps for backwards compatibility: the Icelandic króna and the Ugandan shilling have no decimals,
 * and the API still carries their amounts with two, always `00`. Five krónur are `500` to Stripe and `5` to
 * `Money`, which keeps the currency's own exponent.
 *
 * @see https://docs.stripe.com/currencies#special-cases
 */
final class StripeAmount
{
    /** The currencies Stripe states with two decimals although they have none. */
    private const array STATED_WITH_TWO_DECIMALS = ['ISK', 'UGX'];

    /** The amount Stripe's API expects for this money. */
    public static function of(Money $money): int
    {
        return self::statedWithTwoDecimals($money->currency) ? $money->minorUnits * 100 : $money->minorUnits;
    }

    /** Money for an amount Stripe stated in this currency. */
    public static function toMoney(int $amount, string $currency): Money
    {
        return Money::of(self::statedWithTwoDecimals($currency) ? intdiv($amount, 100) : $amount, strtoupper($currency));
    }

    private static function statedWithTwoDecimals(string $currency): bool
    {
        return in_array(strtoupper($currency), self::STATED_WITH_TWO_DECIMALS, true);
    }
}
