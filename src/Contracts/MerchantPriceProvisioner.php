<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\Money;

/**
 * Creates the provider price a merchant-defined tier is sold at, and hands back the id the host persists on
 * the tier row.
 *
 * A creator defining their own tier has an amount and an interval but no provider price yet — this is what
 * mints one on the provider so the tier becomes purchasable. It is driver-provided (a Stripe price is a
 * Stripe concept), so it is bound by the active driver rather than by the package.
 *
 * It is idempotent by (merchant, tier_key, amount, interval): provisioning the same combination twice returns
 * the same price rather than a duplicate — a provider price is immutable, so an unchanged tier must reuse the
 * one it already has, while a genuine price change (a different amount) is a new price the host records in
 * its place.
 */
interface MerchantPriceProvisioner
{
    public function provision(Model $merchant, string $tierKey, Money $amount, BillingInterval $interval): string;
}
