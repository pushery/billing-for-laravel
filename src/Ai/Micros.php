<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * A configured amount, such as `'0.25'` per million tokens or a cap of `'50.00'`, in millionths of its currency.
 *
 * Read from the decimal string without a float in between, because a float turns `0.1` into a number that is not
 * `0.1`, and a rate that is off by a rounding error is off on every call.
 */
final class Micros
{
    /** @throws InvalidBillingConfig when the value is not a non-negative decimal with at most six places */
    public static function parse(mixed $value, string $key): int
    {
        if (is_int($value) && $value >= 0) {
            return $value * 1_000_000;
        }

        if (! is_string($value) || preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', trim($value), $parts) !== 1) {
            throw InvalidBillingConfig::forKey($key, 'a non-negative decimal amount such as \'0.25\', with at most six decimal places');
        }

        return ((int) $parts[1]) * 1_000_000 + (int) str_pad($parts[2] ?? '', 6, '0');
    }
}
