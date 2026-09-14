<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;

/**
 * The period's payment on a driver the package bills itself, and there never is one.
 *
 * `LocalBillingEngine` bills in ARREARS: an order names the period it closes and is collected at that period's end,
 * and the payment at checkout is a verification amount rather than the plan price. So while a period is in progress
 * nothing has been paid for it, and a withdrawal inside it has nothing to refund. Ending the subscription stops the
 * collection that would have closed the period, so the days already used are not billed either, the outcome
 * `cancelNow()` has always had on this driver.
 *
 * Answering from the orders table instead would look more thorough and be wrong: the newest paid order belongs to a
 * period already provided in full, and a pro-rata refund against it would return money for days the buyer used.
 */
final readonly class LocalSubscriptionPayments implements ReadsSubscriptionPayments
{
    public function currentPeriodPayment(Subscription $subscription): ?SubscriptionPeriodPayment
    {
        return null;
    }
}
