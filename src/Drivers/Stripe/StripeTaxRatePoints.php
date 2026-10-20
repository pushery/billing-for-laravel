<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

/**
 * A Stripe tax percentage as a whole number of basis points, read one way for everything that stores a rate.
 *
 * A stored rate is an integer count of basis points, so 19% and 8.5% fit and 8.875% does not, and a rate the column
 * rounded would no longer reproduce the amounts printed beside it. Stripe states the percentage as a float, and a
 * float times a hundred does not land on the whole number for every rate that is one: 4.9 becomes
 * 490.00000000000006 and 9.45 becomes 944.9999999999999. So the product is rounded to six places first, far below a
 * basis point and far above the error of one multiplication, and only then held to being whole.
 *
 * @internal
 */
final class StripeTaxRatePoints
{
    /**
     * The percentage as basis points, or null for anything that is not a non-negative whole number of them.
     */
    public static function of(mixed $percentage): ?int
    {
        if (! is_int($percentage) && ! is_float($percentage)) {
            return null;
        }

        $points = round((float) $percentage * 100.0, 6);

        return $points >= 0.0 && $points === floor($points) ? (int) $points : null;
    }
}
