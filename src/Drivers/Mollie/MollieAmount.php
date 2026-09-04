<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use InvalidArgumentException;
use Mollie\Api\Http\Data\Money as MollieMoney;
use Pushery\Billing\ValueObjects\Money;

/**
 * Between the package's minor units and Mollie's decimal strings.
 *
 * Stripe and Adyen speak integer minor units, Mollie speaks `{"currency":"EUR","value":"19.00"}`. The
 * conversion itself is neutral and already lives on {@see Money} — `toDecimal()` and `fromDecimal()` both
 * work from the currency's minor-unit exponent, so a zero-decimal currency renders without a point and a
 * three-decimal one keeps all three.
 *
 * What lives HERE is only the provider's shape. Putting a `toMollie()` on the value object every driver
 * shares would name one provider in the neutral core — the same leak `ArchTest` refuses for imports, one
 * layer down, and it would invite an `toAdyen()` beside it until the core knew every driver by name.
 *
 * Nothing rounds. `fromDecimal()` refuses an amount with more precision than the currency has rather than
 * truncating it: a provider sending "19.005" for a two-decimal currency is saying something we do not
 * understand, and choosing 19.00 or 19.01 on their behalf is a guess about somebody's money.
 */
final readonly class MollieAmount
{
    /** The amount as Mollie's request payload expects it. */
    public static function toMollie(Money $amount): MollieMoney
    {
        return new MollieMoney($amount->currency, $amount->toDecimal());
    }

    /** An amount Mollie sent back, as minor units. */
    public static function fromMollie(MollieMoney $amount): Money
    {
        return Money::fromDecimal($amount->value, $amount->currency);
    }

    /**
     * The same, from the shape a RESOURCE carries.
     *
     * Two methods rather than one that guesses, because the SDK genuinely uses two shapes: a request
     * carries the typed `Money` data object, while a response's `amount` is the decoded JSON — a plain
     * object with `currency` and `value`. One method accepting both would have to inspect what it was
     * given, and a caller reading it could no longer tell which direction it was in.
     *
     * A shape that is neither is refused rather than coerced. It means the response was not what we asked
     * for, and inventing a zero there would report a charge of nothing as a real amount.
     */
    public static function fromResource(mixed $amount): Money
    {
        if (! is_object($amount) || ! isset($amount->value, $amount->currency)
            || ! is_string($amount->value) || ! is_string($amount->currency)) {
            throw new InvalidArgumentException(
                'A Mollie resource amount must carry a string `value` and `currency`. What arrived does '.
                'not, which means the response was not the resource we asked for — reading a zero out of '.
                'it would report a charge of nothing as a real amount.'
            );
        }

        return Money::fromDecimal($amount->value, $amount->currency);
    }
}
