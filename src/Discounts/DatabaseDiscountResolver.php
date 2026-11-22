<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * Resolves a coupon code against the package's own `billing_coupons` table, for the seller of one sale.
 *
 * ## Why this exists
 *
 * The table gained an issuer so a merchant could run a discount code of their own, and the redeemer, the
 * model scope and the Stripe mapping all learned to respect it. Nothing resolved a row, though: the only
 * resolver read `billing.coupons`, so a code that existed only as a row was unknown to every path that asks
 * "does this code do anything" first — and the Stripe checkout asks exactly that before it looks at the row.
 * A merchant's code could be created and scoped and was never applied.
 *
 * ## What counts
 *
 * The row issued by this sale's seller, and only while it is active and unexpired: the same two conditions
 * the local driver's coupon question applies. The redemption cap is deliberately not read. It is a race by
 * nature, and the only place it can be enforced truthfully is the redeemer's locked transaction. A row whose
 * type or value cannot be a discount resolves to nothing, the way a malformed config entry does.
 *
 * A coupon meant before tax, `net_percent` or `net_fixed`, resolves to nothing as well. A hosted checkout discounts
 * the price the provider charges, and only the local engine prices the net a cycle is taxed on.
 */
final readonly class DatabaseDiscountResolver implements DiscountResolver
{
    public function resolve(string $code, ?MerchantScope $merchant = null): ?Discount
    {
        $coupon = CouponCodes::find(Coupon::model()::query()->issuedBy($merchant), $code);

        if (! $coupon instanceof Coupon || ! $coupon->isLive()) {
            return null;
        }

        if ($coupon->type === 'percent' && $coupon->value >= 1 && $coupon->value <= 100) {
            return Discount::percentage($coupon->code, $coupon->value);
        }

        if ($coupon->type === 'fixed' && is_string($coupon->currency)) {
            return Discount::fixed($coupon->code, Money::of($coupon->value, $coupon->currency));
        }

        return null;
    }
}
