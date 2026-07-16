<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells the owner their subscription was canceled and — the part that actually matters to them — exactly
 * when their access ends. A cancellation without that date is the notice customers write in about.
 *
 * The once-per-cancellation guarantee lives in the caller (the webhook effect dedups on the subscription
 * and its access-end moment, not on the delivery), so an implementation simply delivers.
 */
interface SubscriptionNotifier
{
    public function subscriptionCanceled(Model $owner, DateTimeInterface $accessEndsAt): void;

    /**
     * The subscription is live: confirm it and name the tier. Distinct from the receipt — that is about the
     * money, this is about the thing they bought being switched on.
     *
     * @param  string  $tierLabel  the tier's human label, already resolved
     */
    public function subscriptionActivated(Model $owner, string $tierLabel): void;
}
