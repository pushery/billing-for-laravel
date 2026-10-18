<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\LocalBillingEngine;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * What a change inside a period collected in advance is worth for the rest of that period, and how it is squared.
 *
 * The period was paid when it began, at the terms in force then. A change takes effect at once, so the time from the
 * change to the period's end is valued twice: at the old terms, which the customer paid for, and at the new ones,
 * which they now have. A positive difference is charged at once, as an order of its own. A negative one becomes
 * credit on the customer's balance, which the next payments spend first; nothing is paid out.
 *
 * Each tier is valued over its own interval, measured from the start of the period: a monthly tier changed for a
 * yearly one is worth a twelfth of the yearly price for a month, not the yearly price. The period keeps its end, and
 * from there the new tier is billed in full over its own interval.
 *
 * Only a period that was paid is squared. A free trial charges nothing for its days, so a change inside it changes
 * nothing that was paid; a period whose payment failed is collected again at the terms in force by then.
 */
final readonly class RestOfPeriod
{
    public function __construct(
        private LocalBillingEngine $engine,
        private CreditLedger $credit,
    ) {}

    /**
     * What moving the subscription from one tier to another at the given moment adds to the period it is in, or takes
     * off it: positive for a dearer tier, negative for a cheaper one, zero where the period was not paid.
     *
     * Null where the subscription does not know its period, the case the screen shows no estimate for.
     */
    public function forTierChange(Subscription $subscription, Plan $from, Plan $to, CarbonInterface $at): ?Money
    {
        $remaining = $this->secondsRemaining($subscription, $at);

        if ($remaining === null) {
            return null;
        }

        if ($from->amount->currency !== $to->amount->currency || ! $this->paidOrderOf($subscription) instanceof Order) {
            return Money::zero($to->amount->currency);
        }

        $perSecond = $to->amount->minorUnits / $this->intervalSeconds($subscription, $to->interval)
            - $from->amount->minorUnits / $this->intervalSeconds($subscription, $from->interval);

        return Money::of((int) round(($subscription->seat_quantity ?? 1) * $remaining * $perSecond), $to->amount->currency);
    }

    /**
     * What changing the seats from one count to another at the given moment adds to the period the subscription is in,
     * or takes off it, at the price of the tier it is on. Zero where the period was not paid, null where the
     * subscription does not know its period.
     */
    public function forSeatChange(Subscription $subscription, Plan $plan, int $from, int $to, CarbonInterface $at): ?Money
    {
        $remaining = $this->secondsRemaining($subscription, $at);

        if ($remaining === null) {
            return null;
        }

        if (! $this->paidOrderOf($subscription) instanceof Order) {
            return Money::zero($plan->amount->currency);
        }

        $value = ($to - $from) * $remaining * $plan->amount->minorUnits / $this->intervalSeconds($subscription, $plan->interval);

        return Money::of((int) round($value), $plan->amount->currency);
    }

    /**
     * Square a difference: charge a positive one for the rest of the period, credit a negative one to the owner.
     *
     * The credit gives back no more than the period's payment holds for the time left, so a period a coupon made
     * cheaper is never credited at the full price. It names the invoice of that payment as its source, where one was
     * raised: the correction it eventually needs belongs to that invoice.
     */
    public function square(Model $owner, Subscription $subscription, Money $difference, string $description, CarbonInterface $at): void
    {
        if ($difference->isPositive()) {
            $this->engine->chargeTheRestOfThePeriod($subscription, $difference, $description, $at);

            return;
        }

        $paid = $this->paidOrderOf($subscription);
        $remaining = $this->secondsRemaining($subscription, $at);

        if (! $difference->isNegative() || ! $paid instanceof Order || $remaining === null) {
            return;
        }

        $credit = min(abs($difference->minorUnits), $this->unusedShareOf($paid, $remaining, $subscription));

        if ($credit < 1) {
            return;
        }

        $invoice = InvoiceRecord::model()::query()->where('order_id', $paid->getKey())->first();

        $this->credit->credit(
            $owner,
            Money::of($credit, $difference->currency),
            CreditReason::ProrationCredit,
            CreditSource::for($invoice instanceof InvoiceRecord ? $invoice : $paid),
        );
    }

    /**
     * The part of the period's payment that buys the time left, in minor units: what the order took before any credit
     * paid part of it, shared by time over the period.
     */
    private function unusedShareOf(Order $paid, int $remaining, Subscription $subscription): int
    {
        // The credit line is negative, so taking it off the total adds back what the credit paid.
        $gross = $paid->total_minor;

        foreach ($paid->items()->where('type', OrderItemType::Credit)->get() as $line) {
            $gross -= $line->total_minor;
        }

        $period = $this->periodSeconds($subscription);

        return $period < 1 ? 0 : (int) round($gross * $remaining / $period);
    }

    /** The order that paid the period the subscription is in, or null where none was paid. */
    private function paidOrderOf(Subscription $subscription): ?Order
    {
        $order = Order::model()::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('period_start', $subscription->current_period_start)
            ->where('status', OrderStatus::Paid)
            ->first();

        return $order instanceof Order ? $order : null;
    }

    /**
     * The seconds from the moment to the end of the period, never more than the period holds and never fewer than
     * none. Null where the subscription does not know its period.
     */
    private function secondsRemaining(Subscription $subscription, CarbonInterface $at): ?int
    {
        $end = $subscription->current_period_end;

        if (! $subscription->current_period_start instanceof Carbon || ! $end instanceof Carbon) {
            return null;
        }

        return max(0, min($this->periodSeconds($subscription), $end->getTimestamp() - $at->getTimestamp()));
    }

    /** How long the period the subscription is in runs, in seconds. */
    private function periodSeconds(Subscription $subscription): int
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        return $start instanceof Carbon && $end instanceof Carbon ? max(0, $end->getTimestamp() - $start->getTimestamp()) : 0;
    }

    /**
     * How long a plan's own interval runs from the start of the period, in seconds: the time its price buys. At least
     * one second, so nothing divides by zero.
     */
    private function intervalSeconds(Subscription $subscription, BillingInterval $interval): int
    {
        $start = $subscription->current_period_start ?? Carbon::now();

        return max(1, $interval->advance($start)->getTimestamp() - $start->getTimestamp());
    }
}
