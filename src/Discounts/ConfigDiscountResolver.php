<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * Resolves a coupon code against the config-defined `billing.coupons` map. The whole map is read and
 * indexed by code rather than reached through a dotted config path, so a code is matched literally
 * and can never be split on a dot. Anything invalid — an unknown code, an expired one, or a
 * malformed entry — resolves to null. Resolving is ALL this does: no package path applies the
 * resolved Discount to a charge, a subscription or an invoice. An app that offers coupons resolves
 * the code here and applies the result itself (Discount::applyTo).
 *
 * The map carries no issuer, so the scope of the sale is not consulted: a code declared here is the
 * platform's and resolves on every sale, a merchant's included. That is deliberate, and it is what an
 * installation declaring its coupons in config has always had. A code only one seller should honor is a
 * `billing_coupons` row issued by that seller, which {@see DatabaseDiscountResolver} reads under the scope
 * and the bound {@see LayeredDiscountResolver} asks first.
 */
final readonly class ConfigDiscountResolver implements DiscountResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(string $code, ?MerchantScope $merchant = null): ?Discount
    {
        $coupons = $this->config->get('billing.coupons');
        $key = is_array($coupons) ? CouponCodes::keyIn($coupons, $code) : null;
        $coupon = is_array($coupons) && $key !== null ? ($coupons[$key] ?? null) : null;

        if ($key === null || ! is_array($coupon) || $this->expired($coupon)) {
            return null;
        }

        $percent = $coupon['percent'] ?? null;

        if (is_int($percent) && $percent >= 1 && $percent <= 100) {
            return Discount::percentage($key, $percent);
        }

        $amount = $coupon['amount'] ?? null;
        $currency = $coupon['currency'] ?? null;

        if (is_int($amount) && is_string($currency)) {
            return Discount::fixed($key, Money::of($amount, $currency));
        }

        return null;
    }

    /**
     * Whether the code is past its `expires_at`. A date alone names the last day the code resolves on, through
     * the end of that day: a code advertised until 31 December still works on the 31st. A moment with a time
     * of day is the moment it stops.
     *
     * @param  array<array-key, mixed>  $coupon
     */
    private function expired(array $coupon): bool
    {
        $expiresAt = $coupon['expires_at'] ?? null;

        if (! is_string($expiresAt)) {
            return false;
        }

        $end = Carbon::parse($expiresAt);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($expiresAt)) === 1) {
            $end = $end->endOfDay();
        }

        return $end->isPast();
    }
}
