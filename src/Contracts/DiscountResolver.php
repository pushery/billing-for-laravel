<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Discount;

/**
 * Resolves a coupon code to a package-owned Discount, or null when the code is invalid/expired.
 * Resolution only: the caller applies the returned Discount (Discount::applyTo) — no package path
 * applies it to a charge, a subscription or an invoice.
 */
interface DiscountResolver
{
    public function resolve(string $code): ?Discount;
}
