<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Support\Carbon;

/**
 * Usage has been sitting in the outbox, unreported, for longer than it should.
 *
 * The flusher exits successfully on a provider outage — a growing backlog is not a crash, and a non-zero
 * exit would make a scheduler escalate an outage it cannot fix. But a backlog that never drains is not an
 * outage any more, it is lost revenue accruing quietly: past the provider's acceptance window a meter event
 * is not retro-billed at all.
 *
 * This fires once the OLDEST pending rollup is older than `billing.usage.stall_hours`, so somebody finds out
 * from an alert rather than from an invoice.
 */
final readonly class UsageBacklogStalled implements BillingDomainEvent
{
    public function __construct(
        public int $pendingRollups,
        public int $pendingUnits,
        public Carbon $oldestRecordedAt,
        public int $stalledHours,
    ) {}
}
