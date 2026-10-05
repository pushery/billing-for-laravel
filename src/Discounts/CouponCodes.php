<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Pushery\Billing\Models\Coupon;

/**
 * Which coupon a customer's code names, by one rule on every database and for every source.
 *
 * A code matches whatever its case and the whitespace around it, as Stripe matches a promotion code: a customer
 * who types `save10` for `SAVE10` means it. Left to the database, the answer followed the column's collation.
 * MySQL's default collations ignore case and accents, `utf8mb4_unicode_ci` trailing spaces as well, so `SÄVE10`
 * found `SAVE10`; PostgreSQL compares exactly, so `save10` found nothing. The query only narrows by the
 * upper-cased code, and the comparison that decides is made here, in PHP, where no collation reaches it.
 *
 * Two codes of one issuer that differ only in case can stand side by side where the database allows it, on
 * PostgreSQL and under a binary collation, and so can two keys of `billing.coupons`. The exact spelling then
 * wins, and without one neither does: guessing which of two discounts a customer meant would apply one of them
 * to a sale it was not meant for.
 */
final class CouponCodes
{
    /**
     * The coupon a code names among those the query admits, which the caller has scoped to an issuer.
     *
     * @param  Builder<Coupon>  $coupons
     */
    public static function find(Builder $coupons, string $code): ?Coupon
    {
        $wanted = self::normalized($code);

        if ($wanted === '') {
            return null;
        }

        $matches = (clone $coupons)->whereRaw('upper(trim(code)) = ?', [$wanted])->get()
            ->filter(static fn (Coupon $coupon): bool => self::normalized($coupon->code) === $wanted)
            ->values();

        return $matches->count() <= 1
            ? $matches->first()
            : $matches->first(static fn (Coupon $coupon): bool => Str::trim($coupon->code) === Str::trim($code));
    }

    /**
     * The key of `billing.coupons` a code names, by the same rule.
     *
     * @param  array<array-key, mixed>  $coupons
     */
    public static function keyIn(array $coupons, string $code): ?string
    {
        $wanted = self::normalized($code);

        if ($wanted === '') {
            return null;
        }

        $keys = [];

        foreach (array_keys($coupons) as $key) {
            if (self::normalized((string) $key) === $wanted) {
                $keys[] = (string) $key;
            }
        }

        if (count($keys) <= 1) {
            return $keys[0] ?? null;
        }

        return in_array(Str::trim($code), $keys, true) ? Str::trim($code) : null;
    }

    /**
     * A code as it is compared: without the whitespace around it, in upper case.
     *
     * The whitespace is Unicode's, invisible characters included. A code copied out of an email arrives with what
     * the mail program put around it, a non-breaking space or a zero-width one, and PHP's trim() removes neither.
     */
    private static function normalized(string $code): string
    {
        return mb_strtoupper(Str::trim($code), 'UTF-8');
    }
}
