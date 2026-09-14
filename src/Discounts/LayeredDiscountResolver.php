<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Pushery\Billing\Contracts\DiscountResolver;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The bound resolver: the seller's own live coupon row first, the platform's config map second.
 *
 * ## Why the row answers first
 *
 * The Stripe checkout already prefers a row's `provider_coupon_id` over `billing.coupons.<code>.stripe_coupon`,
 * because the row describes the coupon it sits on while the config is one global map. Resolving in the other
 * order would let the two disagree whenever both define a code for the same seller: this would report the
 * config's discount while the provider applied the row's. Row first keeps the answer and the money the same.
 *
 * An installation that keeps its coupons only in config gets exactly the answers it got before. A dead row does
 * not hide a live config entry, and another seller's row is no row at all.
 */
final readonly class LayeredDiscountResolver implements DiscountResolver
{
    public function __construct(
        private DatabaseDiscountResolver $rows,
        private ConfigDiscountResolver $config,
    ) {}

    public function resolve(string $code, ?MerchantScope $merchant = null): ?Discount
    {
        return $this->rows->resolve($code, $merchant) ?? $this->config->resolve($code, $merchant);
    }
}
