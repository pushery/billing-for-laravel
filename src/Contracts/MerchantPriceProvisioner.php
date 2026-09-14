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
 *
 * A null interval mints a ONE-TIME price, for a single purchase such as a paid post: no recurring component,
 * on the same account a recurring price for this merchant would live on, so the checkout that charges it finds
 * it. `$tierKey` is then the key of the item being sold. The idempotency key tells the two apart, so a one-time
 * price never reuses a recurring one of the same amount, or the other way round.
 */
interface MerchantPriceProvisioner
{
    public function provision(Model $merchant, string $tierKey, Money $amount, ?BillingInterval $interval): string;
}
