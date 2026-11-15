<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Exceptions\SubscriptionNotPermitted;
use Pushery\Billing\ValueObjects\SubscriptionStart;

/**
 * Begin a subscription on the mandate an owner has already given, without sending them to the provider.
 *
 * A sibling of `StartsSubscriptions` rather than a branch inside its `start()`. A customer who presses Subscribe may mean
 * to give a new payment method, and starting them on the one they gave before would draw on it without anybody choosing
 * that. So a stored mandate is drawn on only where the application calls this, because the customer chose it there:
 * an account holder who gave a direct debit mandate on the application's own form, or a customer whose card mandate
 * was taken on a checkout the application ran.
 *
 * Bound where the package's own engine bills the cycles, which is the Mollie driver. Ask the container with
 * `app()->bound(StartsOverAMandate::class)` before offering it.
 */
interface StartsOverAMandate
{
    /**
     * Start a subscription on a configured tier, charged to the owner's default mandate.
     *
     * The subscription is written at once. Where the tier gives a subscription trial the owner has not had, it is in that
     * trial, and the first run comes at the trial's end. Otherwise it is active from now, and its first period is collected
     * by the next run where it is collected in advance, or at the period's end where it is collected in arrears. A coupon
     * code is honored as `start()` honors it. A generic trial stands on the owner rather than on a subscription, so this
     * call grants none.
     *
     * @param  ?string  $declarationReference  the key the buyer's withdrawal declarations were recorded under, carried
     *                                         onto the subscription so the ledger finds them again
     * @param  ?BillingInterval  $interval  the interval to sell the tier at; null sells it at its default interval, and a
     *                                      driver that bills a tier at its default interval alone refuses another
     *
     * @throws SubscriptionNotPermitted when the tier is not one this install sells, the billable already has a live
     *                                  subscription, or the billable holds no mandate the engine can charge
     */
    public function startOverTheMandate(Model $billable, string $tierKey, ?string $couponCode = null, ?string $declarationReference = null, ?BillingInterval $interval = null): SubscriptionStart;
}
