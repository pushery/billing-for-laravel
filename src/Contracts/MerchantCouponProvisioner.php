<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use InvalidArgumentException;
use Pushery\Billing\Models\Coupon;

/**
 * Creates the provider coupon a local coupon row is redeemed through, and hands back the id to persist on it.
 *
 * ## Why the package does this and not the application
 *
 * {@see Coupon}'s own docblock says the CONSUMING APPLICATION writes that row, and it is right: a discount
 * is a commercial decision and its catalog belongs to whoever runs the business. The provider object is a
 * different thing. It is a provider operation, it needs the driver's client, and the application has no seam
 * to reach it through — exactly the split the price side already makes with
 * {@see MerchantPriceProvisioner}, where the tier is the host's decision and the provider price is the
 * package's job.
 *
 * Without it a merchant-issued code discounts nothing on a hosted checkout: the driver looks for
 * `provider_coupon_id`, finds it empty, falls back to an unscoped configuration map that a merchant code is
 * not in, and the buyer pays full price with nothing thrown and nothing logged.
 *
 * ## Idempotent over what a provider coupon cannot change
 *
 * A provider coupon is immutable in its money: percent, amount, currency and duration are fixed when it is
 * created. So provisioning is keyed on exactly those, and asking twice for the same discount returns the
 * same coupon rather than a second one. Change the row's value and the key changes with it — the old coupon
 * is simply no longer the one this row provisions, which is the same invalidation a repriced tier gets.
 */
interface MerchantCouponProvisioner
{
    /**
     * The provider's coupon for this row, creating it on first ask.
     *
     * @throws InvalidArgumentException when the row does not describe a discount a provider can carry
     */
    public function provision(Coupon $coupon): string;
}
