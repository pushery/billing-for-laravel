<?php

declare(strict_types=1);

namespace Pushery\Billing\Usage;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UsageHistoryProvider;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\Models\UsageCounter;
use Pushery\Billing\ValueObjects\AddonTopup;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PeriodUsage;

/**
 * The package's default {@see UsageHistoryProvider}: past usage read straight from the persisted
 * columns — the billing_usage_counters rows the meter wrote, and the billing_addon_purchases the owner
 * bought — scoped to the owner and never touching a provider. Column-authoritative by construction, so
 * the history reflects exactly what was metered and paid, independent of any downstream rating.
 */
final class DatabaseUsageHistory implements UsageHistoryProvider
{
    public function periods(Model $owner, int $limit = 12): array
    {
        $rows = UsageCounter::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->orderByDesc('period')
            ->orderBy('meter_key')
            ->limit($limit)
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
        $rows = AddonPurchase::query()
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
                purchasedAt: $row->created_at ?? now(),
                // Fully clawed back: explicitly revoked, or the reversed amount reached the purchase.
                reversed: $row->revoked_at !== null || $row->reversed_minor >= $row->amount_minor,
            ))
            ->all());
    }
}
