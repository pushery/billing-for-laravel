<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\ClientIntent;

/**
 * Open a checkout that turns a visitor into a subscriber. The caller submits a tier KEY, never a price
 * (anti-price-injection, exactly like OneTimeCharge); the driver resolves the price from the plan
 * catalog, opens a hosted checkout in subscription mode, and returns the driver-shaped payload the
 * front-end redirects to (a Stripe Checkout URL, later a Mollie/Adyen equivalent). This is the one seam
 * SubscriptionActions never had — it can cancel/resume/swap an existing subscription, not create one.
 */
interface Checkout
{
    /**
     * A driver-shaped payload (a hosted-checkout redirect) to subscribe the billable to a tier. An
     * optional coupon CODE (never a discount amount — anti-injection, like the tier key) is resolved by
     * the DiscountResolver and applied by the driver; an unknown/expired code is ignored so a bad code
     * never blocks checkout.
     */
    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null): ClientIntent;
}
