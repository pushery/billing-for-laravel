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
use Pushery\Billing\Models\CreditLedgerEntry;
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
 *
 * Where the cycle prices a tier itself, through the subscription's own lines and their resolvers or through a
 * configured step, the catalog price is not what anybody pays: the tier in force is valued at what the period's own
 * order charged for its subscription and usage lines, and the tier changed to at what the next cycle charges for them
 * ({@see LocalBillingEngine::tierCharge()}).
 *
 * The credit for a cheaper tier gives back what the period's payments hold for the time left, counting the period's
 * own order and every charge for the rest of it a dearer tier made since, each over its own span, less what earlier
 * changes credited from it already. Each payment is a source of its own share, so the correction a credit needs when
 * it is spent belongs to the invoice that charged it, at that invoice's rate.
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

        $paid = $this->paidOrderOf($subscription);

        if ($from->amount->currency !== $to->amount->currency || ! $paid instanceof Order) {
            return Money::zero($to->amount->currency);
        }

        $charged = $this->engine->tierCharge($subscription, $to->key, $to->amount);

        // The cycle prices the tier itself, so seats are in the lines already, and the tier in force is worth what its
        // payment charged for the period.
        if ($charged instanceof Money) {
            $perSecond = $charged->minorUnits / $this->intervalSeconds($subscription, $to->interval)
                - $this->recurringChargeOf($paid) / max(1, $this->periodSeconds($subscription));

            return Money::of((int) round($remaining * $perSecond), $to->amount->currency);
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
     * The credit gives back no more than the period's payments hold for the time left, so a period a coupon made
     * cheaper is never credited at the full price, and two changes never give back more than was paid. It is shared
     * among the payments by what each holds, and each share names the invoice of its payment as its source, where one
     * was raised: the correction it eventually needs belongs to that invoice.
     */
    public function square(Model $owner, Subscription $subscription, Money $difference, string $description, CarbonInterface $at): void
    {
        if ($difference->isPositive()) {
            $this->engine->chargeTheRestOfThePeriod($subscription, $difference, $description, $at);

            return;
        }

        $end = $subscription->current_period_end;

        if (! $difference->isNegative() || $this->secondsRemaining($subscription, $at) === null || ! $end instanceof Carbon) {
            return;
        }

        $shares = $this->unusedShares($subscription, $end, $at);
        $held = array_sum(array_column($shares, 1));
        $credit = min(abs($difference->minorUnits), $held);

        if ($credit < 1) {
            return;
        }

        $left = $credit;

        foreach ($shares as $index => [$source, $share]) {
            // Shared by what each payment holds, the last one taking what rounding left, so the parts add up to the credit.
            $part = $index === array_key_last($shares) ? $left : intdiv($credit * $share, $held);
            $left -= $part;

            if ($part > 0) {
                $this->credit->credit($owner, Money::of($part, $difference->currency), CreditReason::ProrationCredit, CreditSource::for($source));
            }
        }
    }

    /**
     * What each payment of the period holds for the time left, with the record a credit from it names, in minor units.
     *
     * The period's own order and every charge for the rest of it count, each over its own span from its start to the
     * period's end, and each less what earlier changes credited from it already. A payment that holds nothing more is
     * left out.
     *
     * @return list<array{Model, int}>
     */
    private function unusedShares(Subscription $subscription, Carbon $end, CarbonInterface $at): array
    {
        $paid = Order::model()::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', OrderStatus::Paid)
            ->where('period_start', '>=', $subscription->current_period_start)
            ->where('period_start', '<', $end)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        $shares = [];

        foreach ($paid as $order) {
            $since = $order->period_start;
            $span = $since instanceof Carbon ? $end->getTimestamp() - $since->getTimestamp() : 0;
            $remaining = max(0, min($span, $end->getTimestamp() - $at->getTimestamp()));
            $invoice = InvoiceRecord::model()::query()->where('order_id', $order->getKey())->first();
            $source = $invoice instanceof InvoiceRecord ? $invoice : $order;
            $share = $span < 1 ? 0 : (int) round($this->grossOf($order) * $remaining / $span) - $this->creditedFrom($source);

            if ($share > 0) {
                $shares[] = [$source, $share];
            }
        }

        return $shares;
    }

    /** What an order took before any credit paid part of it: the credit line is negative, so taking it off adds it back. */
    private function grossOf(Order $order): int
    {
        $gross = $order->total_minor;

        foreach ($order->items()->where('type', OrderItemType::Credit)->get() as $line) {
            $gross -= $line->total_minor;
        }

        return $gross;
    }

    /** What changes of tier or seats credited from a payment already, in minor units. */
    private function creditedFrom(Model $record): int
    {
        $source = CreditSource::for($record);

        return (int) CreditLedgerEntry::model()::query()
            ->where('reason', CreditReason::ProrationCredit)
            ->where('source_type', $source->type)
            ->where('source_id', $source->id)
            ->where('amount_minor', '>', 0)
            ->sum('amount_minor');
    }

    /** What an order charged for the subscription and usage lines of its period, in minor units. */
    private function recurringChargeOf(Order $order): int
    {
        return (int) $order->items()->whereIn('type', [OrderItemType::Subscription, OrderItemType::Usage])->sum('total_minor');
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
