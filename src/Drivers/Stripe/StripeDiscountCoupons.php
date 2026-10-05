<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Stripe\StripeClient;

/**
 * A subscription's discounts with their coupons, read back from Stripe.
 *
 * On the API version this package pins, a subscription names its discounts by id, and a discount names its coupon
 * under `source.coupon`, by id as well. A webhook expands neither, so the coupon's metadata, where a minted merchant
 * coupon carries its local code, is not in the payload at all. This reads the subscription once with both expanded.
 */
final readonly class StripeDiscountCoupons
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * The subscription's discounts, each with its coupon expanded.
     *
     * @return list<mixed>
     */
    public function expandedFor(string $subscriptionId): array
    {
        $discounts = $this->stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['discounts.source.coupon']])->toArray()['discounts'] ?? null;

        return is_array($discounts) ? array_values($discounts) : [];
    }
}
