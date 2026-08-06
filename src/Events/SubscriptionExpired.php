<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Subscription;

/**
 * A subscription in arrears ran out its cure window and has expired for good.
 *
 * ## The one message, and what it has to say
 *
 * This is the LAST message of the series, not an extra one beside the day's reminder — the sweeps partition
 * the delinquent set at the same boundary, so a subscription is either reminded or expired on a given day
 * and never both.
 *
 * A listener MUST name the subscription and the access that is gone. "Your subscription was canceled" with
 * no object is worthless to a customer holding five of them, and it manufactures the support contact it was
 * meant to close. The subscription is carried whole so the merchant can be read from it.
 *
 * ## `$accessEndsAt` is not always now, and that is the point
 *
 * Arrears concern the period that was NOT paid. A period already paid for still runs, and expiry does not
 * claw it back — the decision is that the customer loses access to what they did not pay for, which says
 * nothing about what they did. So this carries the moment access actually stops: now in the ordinary case,
 * later when a paid period is still running. A message that says "your access has ended" on a subscription
 * that is paid for another ten days is telling the customer something untrue, and the value they need to
 * avoid it is right here.
 */
final readonly class SubscriptionExpired implements BillingDomainEvent
{
    public function __construct(
        public Subscription $subscription,
        public Carbon $accessEndsAt,
    ) {}
}
