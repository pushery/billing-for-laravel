<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\ReadsSubscriptionPayments;
use Pushery\Billing\Exceptions\SubscriptionWithdrawalUnavailable;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;
use Stripe\StripeClient;

/**
 * The period's payment on Stripe: the newest invoice that started or renewed the subscription.
 *
 * ## Which invoice, and why not simply the newest
 *
 * Stripe collects a subscription in advance, so the invoice for the period in progress is the newest one raised to
 * START or RENEW it. An invoice raised for a change within the period (a proration, a usage threshold) is not the
 * period's price, and a pro-rata refund measured against it would return a fraction of the wrong amount. Only
 * `subscription_create` and `subscription_cycle` invoices are read, and the newest of those decides.
 *
 * ## The period comes off the invoice
 *
 * The subscription line carries the service period it bills, and it is the line that reaches furthest: a proration
 * line on the same invoice bills part of a period already behind it. The row's own cycle can lag a webhook behind,
 * and dates read from the row could pair this invoice with the next period.
 *
 * ## What each status answers
 *
 * Paid is the payment, including a trial invoiced at nothing. Open or draft is money still on its way, refused
 * rather than read as nothing. Void and uncollectible collected nothing, and a newest invoice whose period has
 * already ended covers nothing in progress, so both answer null.
 */
final readonly class StripeSubscriptionPayments implements ReadsSubscriptionPayments
{
    /** The invoices that price a period, as opposed to a change within one. */
    private const array PERIOD_REASONS = ['subscription_create', 'subscription_cycle'];

    public function __construct(private StripeClient $stripe) {}

    public function currentPeriodPayment(Subscription $subscription): ?SubscriptionPeriodPayment
    {
        if ($subscription->provider_id === null) {
            return null;
        }

        $invoices = $this->stripe->invoices->all(['subscription' => $subscription->provider_id, 'limit' => 10]);

        foreach ($invoices->autoPagingIterator() as $invoice) {
            $data = $invoice->toArray();

            if (in_array($data['billing_reason'] ?? null, self::PERIOD_REASONS, true)) {
                return $this->paymentOf($data);
            }
        }

        return null;
    }

    /** @param  array<array-key, mixed>  $invoice */
    private function paymentOf(array $invoice): ?SubscriptionPeriodPayment
    {
        $id = $invoice['id'] ?? null;
        $period = $this->periodOf($invoice);

        if (! is_string($id) || $period === null || CarbonImmutable::now()->greaterThanOrEqualTo($period[1])) {
            return null;
        }

        return match ($invoice['status'] ?? null) {
            'paid' => new SubscriptionPeriodPayment($id, $this->paid($invoice), $period[0], $period[1]),
            'open', 'draft' => throw SubscriptionWithdrawalUnavailable::paymentInFlight($id),
            default => null,
        };
    }

    /**
     * The service period of the line that reaches furthest, or null when no line names one.
     *
     * @param  array<array-key, mixed>  $invoice
     * @return ?array{CarbonImmutable, CarbonImmutable}
     */
    private function periodOf(array $invoice): ?array
    {
        $lines = $invoice['lines'] ?? null;
        $data = is_array($lines) ? ($lines['data'] ?? null) : null;
        $found = null;

        foreach (is_array($data) ? $data : [] as $line) {
            $period = is_array($line) ? ($line['period'] ?? null) : null;
            $start = is_array($period) ? ($period['start'] ?? null) : null;
            $end = is_array($period) ? ($period['end'] ?? null) : null;

            if (is_int($start) && is_int($end) && ($found === null || $end > $found[1])) {
                $found = [$start, $end];
            }
        }

        return $found === null
            ? null
            : [CarbonImmutable::createFromTimestampUTC($found[0]), CarbonImmutable::createFromTimestampUTC($found[1])];
    }

    /** @param  array<array-key, mixed>  $invoice */
    private function paid(array $invoice): Money
    {
        $amount = $invoice['amount_paid'] ?? 0;
        $currency = $invoice['currency'] ?? '';

        return Money::of(is_int($amount) ? $amount : 0, strtoupper(is_string($currency) ? $currency : ''));
    }
}
