<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Resolves a coupon code to a package-owned Discount, or null when the code is invalid/expired.
 * Resolution only: the caller applies the returned Discount (Discount::applyTo) — no package path
 * applies it to a charge, a subscription or an invoice.
 *
 * The answer belongs to a SALE rather than to the code alone. Two sellers may each issue a code of the
 * same name, so whether `SUMMER25` resolves has no answer until it says whose sale it would discount.
 */
interface DiscountResolver
{
    /**
     * @param  ?MerchantScope  $merchant  the seller of the sale the code would discount; null is the platform
     */
    public function resolve(string $code, ?MerchantScope $merchant = null): ?Discount;
}
