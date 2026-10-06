<?php

declare(strict_types=1);

namespace Pushery\Billing\Usage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\UsageHistoryProvider;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\Models\UsageCounter;
use Pushery\Billing\Support\PeriodResolver;
use Pushery\Billing\ValueObjects\AddonTopup;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PeriodUsage;

/**
 * The package's default {@see UsageHistoryProvider}: past usage read straight from the persisted
 * columns — the billing_usage_counters rows the meter wrote, and the billing_addon_purchases the owner
 * bought — scoped to the owner and never touching a provider. Column-authoritative by construction, so
 * the history reflects exactly what was metered and paid, independent of any downstream rating.
 */
final readonly class DatabaseUsageHistory implements UsageHistoryProvider
{
    public function __construct(private PeriodResolver $cycles = new PeriodResolver) {}

    /**
     * Every meter of the owner's last `$limit` finished periods, newest period first.
     *
     * The limit counts periods, not rows: an owner with five meters sees each period whole, where a row
     * limit of twelve cut the oldest card short and hid meters as if they had not been used. The running
     * period is left out, because its figures are still moving; the overview shows them.
     */
    public function periods(Model $owner, int $limit = 12): array
    {
        $finished = UsageCounter::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('period', '!=', $this->cycles->forOwner($owner)->key)
            ->distinct()
            ->orderByDesc('period')
            ->limit($limit)
            ->pluck('period');

        $rows = UsageCounter::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereIn('period', $finished)
            ->orderByDesc('period')
            ->orderBy('meter_key')
            ->get();

        return array_values($rows
            ->map(static fn (UsageCounter $row): PeriodUsage => new PeriodUsage(
                period: $row->period,
                meterKey: $row->meter_key,
                used: $row->used,
                prepaidUsed: $row->prepaid_used,
            ))
            ->all());
    }

    public function topups(Model $owner, int $limit = 24): array
    {
        $rows = AddonPurchase::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            // Newest first, with the id as a tiebreaker so purchases in the same second still order stably.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return array_values($rows
            ->map(static fn (AddonPurchase $row): AddonTopup => new AddonTopup(
                addonKey: $row->addon_key,
                amount: Money::of($row->amount_minor, $row->currency),
                // created_at is set on insert; fall back to now only for the degenerate untimestamped row.
                purchasedAt: $row->created_at ?? Carbon::now(),
                // Fully clawed back: explicitly revoked, or the reversed amount reached the purchase. A purchase that
                // cost nothing, such as a fully discounted checkout, has no amount to reach, so only a revocation
                // reverses it.
                reversed: $row->revoked_at !== null || ($row->amount_minor > 0 && $row->reversed_minor >= $row->amount_minor),
            ))
            ->all());
    }
}
