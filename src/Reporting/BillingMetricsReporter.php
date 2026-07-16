<?php

declare(strict_types=1);

namespace Pushery\Billing\Reporting;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Computes {@see BillingMetrics} from the local subscription rows — no provider round-trip.
 *
 * MRR is the monthly-normalized DECLARED list price: each active tier's `price_display`, a yearly plan
 * divided by twelve, a weekly one times 52/12, summed in the configured billing currency. It is what
 * your CATALOG says you charge, not what the provider actually collected after a coupon or a mid-cycle
 * proration — a deliberately provider-independent, plan-level number. A tier with no `price_display`
 * (the free tier) contributes nothing, and MRR assumes a single billing currency: prices declared in a
 * currency other than `billing.currency` are summed by their minor units all the same, so keep the
 * catalog single-currency if you read MRR.
 */
final readonly class BillingMetricsReporter
{
    public function __construct(private PlanCatalog $plans, private Repository $config) {}

    public function compute(int $windowDays = 30): BillingMetrics
    {
        $now = Carbon::now();

        $mrrMinor = 0;
        $activeCount = 0;

        // One pass over the active rows serves both the count and the MRR sum.
        Subscription::query()->where('status', 'active')->cursor()->each(function (Subscription $sub) use (&$mrrMinor, &$activeCount): void {
            $activeCount++;

            $plan = $sub->tier_key !== null ? $this->plans->planFor($sub->tier_key) : null;

            if ($plan instanceof Plan) {
                $mrrMinor += (int) round($plan->amount->minorUnits * $plan->interval->perYear() / 12);
            }
        });

        $trials = Subscription::query()
            ->where(static fn (Builder $q): Builder => $q->where('status', 'trialing')->orWhere('trial_ends_at', '>', $now))
            ->count();

        // Past-due covers a webhook-synced (Stripe) row; a raised dunning level covers a local-engine row.
        $inDunning = Subscription::query()
            ->where(static fn (Builder $q): Builder => $q->where('status', 'past_due')->orWhere('dunning_level', '>', 0))
            ->count();

        // Churn: rows whose subscription ended (grace over) within the trailing window.
        $canceledInWindow = Subscription::query()
            ->whereBetween('ends_at', [$now->copy()->subDays($windowDays), $now])
            ->count();

        return new BillingMetrics(
            mrr: Money::of($mrrMinor, $this->currency()),
            activeSubscriptions: $activeCount,
            trials: $trials,
            inDunning: $inDunning,
            canceledInWindow: $canceledInWindow,
            windowDays: $windowDays,
        );
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.currency', 'EUR');

        return is_string($currency) ? $currency : 'EUR';
    }
}
