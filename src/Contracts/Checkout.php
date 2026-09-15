<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\ClientIntent;

/**
 * Open a checkout that turns a visitor into a subscriber. The caller submits a tier KEY, never a price
 * (anti-price-injection, exactly like OneTimeCharge); the driver resolves the price from the plan
 * catalog, opens a hosted checkout in subscription mode, and returns the driver-shaped payload the
 * front-end redirects to (a Stripe Checkout URL, later another driver's equivalent). This is the one seam
 * SubscriptionActions never had — it can cancel/resume/swap an existing subscription, not create one.
 */
interface Checkout
{
    /**
     * A driver-shaped payload (a hosted-checkout redirect) to subscribe the billable to a tier. An
     * optional coupon CODE (never a discount amount — anti-injection, like the tier key) is resolved by
     * the DiscountResolver and applied by the driver; an unknown/expired code is ignored so a bad code
     * never blocks checkout.
     *
     * @param  ?string  $declarationReference  the key the package minted for the buyer's withdrawal declarations
     *                                         (PurchaseDeclarations::declare()), carried to the provider so the
     *                                         subscription the webhook reports finds them again; null sends exactly
     *                                         what was sent before the parameter existed
     * @param  ?string  $buyerCountry  the buyer's ISO country where the caller already knows it; checked against
     *                                 `billing.tax_markets` before the provider is asked for anything, so a market that
     *                                 is not open throws MarketNotOpen. Null checks nothing here
     * @param  ?bool  $collectTaxId  whether THIS checkout asks the buyer for a tax ID while the provider computes tax;
     *                               null follows `billing.checkout.tax_id_collection`. A marketplace selling to both
     *                               consumers and businesses leaves the field out of the one and asks for it on the other
     */
    public function subscribe(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?string $buyerCountry = null, ?bool $collectTaxId = null): ClientIntent;
}
