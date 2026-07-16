<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\Money;

/**
 * Resolves a coupon code against the config-defined `billing.coupons` map. The whole map is read and
 * indexed by code rather than reached through a dotted config path, so a code is matched literally
 * and can never be split on a dot. Anything invalid — an unknown code, an expired one, or a
 * malformed entry — resolves to null. Resolving is ALL this does: no package path applies the
 * resolved Discount to a charge, a subscription or an invoice. An app that offers coupons resolves
 * the code here and applies the result itself (Discount::applyTo).
 */
final readonly class ConfigDiscountResolver implements DiscountResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(string $code): ?Discount
    {
        $coupons = $this->config->get('billing.coupons');
        $coupon = is_array($coupons) ? ($coupons[$code] ?? null) : null;

        if (! is_array($coupon) || $this->expired($coupon)) {
            return null;
        }

        $percent = $coupon['percent'] ?? null;

        if (is_int($percent) && $percent >= 1 && $percent <= 100) {
            return Discount::percentage($code, $percent);
        }

        $amount = $coupon['amount'] ?? null;
        $currency = $coupon['currency'] ?? null;

        if (is_int($amount) && is_string($currency)) {
            return Discount::fixed($code, Money::of($amount, $currency));
        }

        return null;
    }

    /** @param array<array-key, mixed> $coupon */
    private function expired(array $coupon): bool
    {
        $expiresAt = $coupon['expires_at'] ?? null;

        return is_string($expiresAt) && Carbon::parse($expiresAt)->isPast();
    }
}
