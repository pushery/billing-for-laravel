<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Exceptions\SubscriptionWithdrawalUnavailable;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;

/**
 * The period's payment on a driver the package bills itself: the order that paid the period in progress, where the
 * subscription is collected in advance, and none where it is collected in arrears.
 *
 * In advance, a period is paid when it begins, so the order claimed for the subscription and the start of its current
 * period is the payment a withdrawal or a prorated cancellation refunds the unused part of. One that is still being
 * collected is refused, as the contract says: money may yet arrive for a period the subscriber is leaving.
 *
 * In arrears, an order names the period it closes and is collected at that period's end, and the payment at checkout
 * is a verification amount rather than the plan price. So while a period is in progress nothing has been paid for it,
 * and a withdrawal inside it has nothing to refund. A withdrawal ends the subscription with `cancelNow()`, which stops
 * the collection that would have closed the period, so the days already used are not billed either. Answering from
 * the orders table there would look more thorough and be wrong: the newest paid order belongs to a period already
 * provided in full, and a pro-rata refund against it would return money for days the buyer used.
 */
final readonly class LocalSubscriptionPayments implements ReadsSubscriptionPayments
{
    public function currentPeriodPayment(Subscription $subscription): ?SubscriptionPeriodPayment
    {
        $start = $subscription->current_period_start;

        if (! $subscription->collectsInAdvance() || ! $start instanceof Carbon) {
            return null;
        }

        $order = Order::model()::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('period_start', $start)
            ->first();

        if (! $order instanceof Order || ! $order->period_end instanceof Carbon) {
            return null;
        }

        if ($order->status === OrderStatus::Processing) {
            throw SubscriptionWithdrawalUnavailable::paymentInFlight($order->payment_reference ?? (string) $order->id);
        }

        if ($order->status !== OrderStatus::Paid) {
            return null;
        }

        return new SubscriptionPeriodPayment(
            $order->payment_reference ?? (string) $order->id,
            Money::of($order->total_minor, $order->currency),
            CarbonImmutable::instance($start),
            CarbonImmutable::instance($order->period_end),
        );
    }
}
