<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Usage\DatabaseUsageHistory;

/**
 * One meter's recorded usage in one PAST billing period — the atomic row of a UsageHistory. Read
 * column-authoritatively straight from the persisted usage counter (never a provider call), so the
 * history shows exactly what the package metered, whatever a downstream provider later rated it at.
 *
 * `used` is the metered consumption; `prepaidUsed` is the slice of it that was covered by prepaid
 * (paid-for) units rather than the cycle's free allowance — surfaced so a customer can see, per past
 * period, how much of their usage they actually paid extra for.
 *
 * `metered` defaults to true — the package's own {@see DatabaseUsageHistory} reads
 * from real counters, so every row it emits is genuine metered usage. It exists for the project-overridable
 * seam: a provider surfacing a dimension that was on an unmetered / BYOK tier in a past period sets it false,
 * and the history renders "not metered" rather than a `used` number that would mean nothing.
 */
final readonly class PeriodUsage
{
    public function __construct(
        public string $period,
        public string $meterKey,
        public int $used,
        public int $prepaidUsed,
        public bool $metered = true,
    ) {}
}
