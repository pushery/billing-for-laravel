<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CycleAmountResolver;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\OrderItemDraft;

/**
 * Prices a cycle from the subscription's own LINES, when it has any.
 *
 * The package already had every part of this and no path through them. `SubscriptionItem` carries a
 * `metered` flag and a `preprocessor` naming the resolver for its line; `CycleAmountResolver` is bound to
 * `PlanCycleAmountResolver`, which returns a fixed line's own amount and delegates a metered one to the
 * named resolver; `MeteredCycleAmountResolver` rates that line against the package's usage counters,
 * netting both the tier allowance and any prepaid units. All of it built, tested — and reached by nothing,
 * because the local engine priced a cycle from the TIER price and never looked at the lines.
 *
 * That gap is why a subscription with three lines billed as though it had one. This class is the missing
 * call, not a new mechanism: it asks the resolver that was already bound, for each line that already
 * exists.
 *
 * A subscription with no lines is the ordinary case and gets no drafts back — the engine keeps billing
 * the tier price, exactly as before. Adding lines is what opts in.
 *
 * ## Why the resolver's failure is allowed through
 *
 * `CycleAmountUnresolvable` means a line cannot be priced: a fixed line with no amount, a metered line
 * with no resolver, a meter missing from the catalog. Every one of those is a line written wrong rather
 * than a cycle that is free, so the cycle fails and is retried instead of being billed short. The engine
 * catches per subscription, so one broken line stops that subscriber's cycle and nobody else's.
 */
final readonly class CycleItemPricer
{
    public function __construct(
        private CycleAmountResolver $amounts,
        private PeriodResolver $periods,
    ) {}

    /**
     * The lines this cycle carries, or an empty list when the subscription has none.
     *
     * @return list<OrderItemDraft>
     */
    public function drafts(Subscription $subscription, Model $owner): array
    {
        $items = $subscription->items()->get();

        if ($items->isEmpty()) {
            return [];
        }

        $start = $subscription->current_period_start;

        if ($start === null) {
            return [];
        }

        // Resolved at the cycle's own start, not at "now". A cycle is billed after it closes, so a meter
        // read against the current period would count the days since the rollover — a smaller number,
        // always wrong, and never obviously wrong on the invoice.
        $period = $this->periods->forOwner($owner, $start);

        $drafts = [];

        foreach ($items as $item) {
            $amount = $this->amounts->resolve($item, $period);

            // The line's amount arrives already rated — packages times unit price for a metered line, the
            // stored amount for a fixed one — so the draft carries it as one position. Splitting it back
            // into a unit price and a count here would be guesswork about arithmetic the resolver already
            // did, and the two could disagree.
            $drafts[] = new OrderItemDraft(
                $item->plan_key,
                $amount->minorUnits,
                1,
                $amount->currency,
                $item->metered ? OrderItemType::Usage : OrderItemType::Subscription,
                [
                    'plan_key' => $item->plan_key,
                    'metered' => (bool) $item->metered,
                    'period' => $period->key,
                ],
            );
        }

        return $drafts;
    }
}
