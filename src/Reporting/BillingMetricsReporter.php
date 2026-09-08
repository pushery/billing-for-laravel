<?php

declare(strict_types=1);

namespace Pushery\Billing\Reporting;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;
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
 *
 * ## Per merchant, or across all of them
 *
 * Every figure here can be narrowed to one seller. The money side of a marketplace was already readable per
 * merchant — the charge ledger, the annual earnings counter, the settlement inflow — and the SUBSCRIPTION
 * side was not, which is not a boundary anybody drew but the place the marketplace grew past an older
 * reporter.
 *
 * It belongs here rather than in a consuming application because MRR is a DEFINITION, not a query: which
 * statuses count, how a yearly plan breaks to the month, how a trial counts, what a cancellation at period
 * end does. An application counting per seller on its own produces a SECOND monthly revenue, and the two
 * diverge the first time either changes a status rule — leaving a different number on the seller's page
 * than on the platform's, both defensible.
 *
 * **A null scope means EVERY merchant, and that is not what a null means one level down.**
 * {@see Subscription::scopeForMerchant()} reads null as the PLATFORM's own rows — the single-seller case —
 * because a subscription row always belongs to exactly one seller. Here there is a third thing to ask for,
 * and it is the default: no narrowing at all. Passing null through to that scope would silently turn
 * "everything" into "the platform's own", which on a marketplace is a much smaller number that still looks
 * like a total.
 */
final readonly class BillingMetricsReporter
{
    public function __construct(private PlanCatalog $plans, private Repository $config) {}

    /**
     * @param  ?MerchantScope  $merchant  one seller, or null for every one of them — see the class docblock
     */
    public function compute(int $windowDays = 30, ?MerchantScope $merchant = null): BillingMetrics
    {
        $now = Carbon::now();

        $mrrMinor = 0;
        $activeCount = 0;

        // One pass over the active rows serves both the count and the MRR sum.
        $this->scoped($merchant)->where('status', 'active')->cursor()->each(function (Subscription $sub) use (&$mrrMinor, &$activeCount): void {
            $activeCount++;

            $plan = $sub->tier_key !== null ? $this->plans->planFor($sub->tier_key) : null;

            if ($plan instanceof Plan) {
                $mrrMinor += (int) round($plan->amount->minorUnits * $plan->interval->perYear() / 12);
            }
        });

        $trials = $this->scoped($merchant)
            ->where(static fn (Builder $q): Builder => $q->where('status', 'trialing')->orWhere('trial_ends_at', '>', $now))
            ->count();

        // Past-due covers a webhook-synced (Stripe) row; a raised dunning level covers a local-engine row.
        $inDunning = $this->scoped($merchant)
            ->where(static fn (Builder $q): Builder => $q->where('status', 'past_due')->orWhere('dunning_level', '>', 0))
            ->count();

        // Churn: rows whose subscription ended (grace over) within the trailing window.
        $canceledInWindow = $this->scoped($merchant)
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

    /**
     * A fresh subscription query, narrowed to one seller when one was named.
     *
     * The null branch does NOT fall through to `forMerchant(null)`, which would mean the platform's own
     * rows rather than all of them. Every figure below goes through here so a fifth one added later is
     * narrowed too — the alternative is four identical `where` clauses and a fifth that somebody forgets,
     * which reports a merchant's churn against the whole platform's cancellations.
     *
     * @return Builder<Subscription>
     */
    private function scoped(?MerchantScope $merchant): Builder
    {
        $query = Subscription::query();

        return $merchant instanceof MerchantScope ? $query->forMerchant($merchant) : $query;
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.currency', 'EUR');

        return is_string($currency) ? $currency : 'EUR';
    }
}
