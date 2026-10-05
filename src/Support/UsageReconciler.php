<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Enums\UsageEventState;
use Pushery\Billing\Events\UsageBacklogStalled;
use Pushery\Billing\Events\UsageReconciliationDrift;
use Pushery\Billing\Models\UsageEvent;
use Pushery\Billing\ValueObjects\UsageDrift;

/**
 * Asks the provider what it ACTUALLY recorded, and compares it with what we believe we sent.
 *
 * Usage reporting works — and that is exactly the problem it hides. The local ledger and the provider's
 * meter are two separate sources of truth, and they drift quietly: a report accepted on our side that never
 * arrived, an event the provider dropped, a meter that stopped rating. Nothing throws. The customer simply
 * gets an invoice built on a number nobody checked, and the first person to notice is them.
 *
 * What we compare is the NETTED figure — usage minus the prepaid units the owner had already bought — because
 * that is what actually crossed the wire. Comparing the raw total would report a "drift" on every customer
 * with prepaid units.
 *
 * A driver that bills usage locally has no second source to read back from; it answers null and is reported
 * as unverifiable rather than as drift. An absence of evidence is not evidence of a discrepancy.
 */
final readonly class UsageReconciler
{
    public function __construct(
        private UsageReporter $reporter,
        private PeriodResolver $periods,
        private Repository $config,
    ) {}

    /**
     * Reconcile every owner's CURRENT cycle against the provider.
     *
     * Each owner is loaded once and asked for its current cycle once, and only that cycle's rollups are read. A
     * closed cycle costs nothing: walking every reported cycle and dropping the closed ones afterwards loaded the
     * owner and resolved its period for each of them, so the daily run grew with the history it never acts on.
     *
     * @return list<UsageDrift> the disagreements found (empty when the two sources agree)
     */
    public function reconcile(): array
    {
        $drifts = [];

        foreach ($this->ownersWithReportedUsage() as $holder) {
            foreach ($this->reconcileOwner($holder) as $drift) {
                $drifts[] = $drift;

                Event::dispatch(new UsageReconciliationDrift(
                    $drift->owner,
                    $drift->meterKey,
                    $drift->period,
                    $drift->reported,
                    $drift->recorded,
                ));
            }
        }

        return $drifts;
    }

    /**
     * Fire the stall alarm when the oldest un-reported rollup has been waiting too long.
     *
     * The flusher is deliberately quiet about a backlog — a provider outage is not a crash, and a scheduler
     * cannot fix it by escalating. But a backlog that never drains stops being an outage and becomes lost
     * revenue: past the provider's acceptance window a meter event is not retro-billed at all.
     *
     * @return ?UsageBacklogStalled the alarm that was raised, or null when the outbox is healthy
     */
    public function checkBacklog(): ?UsageBacklogStalled
    {
        $pending = UsageEvent::model()::query()
            ->where('is_rollup', true)
            ->where('state', UsageEventState::Pending->value)
            ->orderBy('created_at')
            ->get();

        $oldest = $pending->first();

        if (! $oldest instanceof UsageEvent) {
            return null;
        }

        $hours = $this->stallHours();
        $age = (int) $oldest->created_at->diffInHours(Carbon::now());

        if ($age < $hours) {
            return null;
        }

        $alarm = new UsageBacklogStalled(
            pendingRollups: $pending->count(),
            pendingUnits: $pending->sum(fn (UsageEvent $event): int => $event->quantity),
            oldestRecordedAt: $oldest->created_at,
            stalledHours: $age,
        );

        Event::dispatch($alarm);

        return $alarm;
    }

    /**
     * One owner's reported usage in its current cycle, checked meter by meter against the provider. The holder is a
     * partially hydrated {@see UsageEvent} carrying only the two owner columns.
     *
     * @return list<UsageDrift>
     */
    private function reconcileOwner(UsageEvent $holder): array
    {
        $owner = $this->ownerOf($holder->owner_type, $holder->owner_id);

        if (! $owner instanceof Model) {
            return [];
        }

        $customer = $this->customerReference($owner);

        if ($customer === null) {
            return [];
        }

        // Only the CURRENT cycle: a closed one cannot be corrected anyway (a meter event past the window is
        // not retro-billed), and its window would have to be reconstructed from a period key that no longer
        // resolves. The reconcile runs daily and at cycle close, which is when it can still act.
        $period = $this->periods->forOwner($owner);
        $drifts = [];

        foreach ($this->reportedRollupsByMeter($holder, $period->key) as $rollups) {
            $meter = $rollups[0]->provider_meter;

            if (! is_string($meter)) {
                continue;
            }

            // What we believe crossed the wire: the usage MINUS the prepaid units it was covered by. Comparing
            // the raw total would flag every prepaid customer as drifting.
            $reported = array_sum(array_map(static fn (UsageEvent $rollup): int => $rollup->quantity - $rollup->prepaid_units, $rollups));

            $recorded = $this->reporter->recordedTotal($customer, $meter, $period->start, $period->end);

            if ($recorded === null || $recorded === $reported) {
                continue; // no second source to compare against, or the two agree
            }

            $drifts[] = new UsageDrift($owner, $rollups[0]->meter_key, $period->key, $reported, $recorded);
        }

        return $drifts;
    }

    /**
     * The reported rollups of one owner in one cycle, read in one query and grouped by meter.
     *
     * @return list<non-empty-list<UsageEvent>>
     */
    private function reportedRollupsByMeter(UsageEvent $holder, string $period): array
    {
        $rollups = UsageEvent::model()::query()
            ->where('is_rollup', true)
            ->where('state', UsageEventState::Reported->value)
            ->where('owner_type', $holder->owner_type)
            ->where('owner_id', $holder->owner_id)
            ->where('period', $period)
            ->orderBy('id')
            ->get();

        $byMeter = [];

        foreach ($rollups as $rollup) {
            $byMeter[$rollup->meter_key][] = $rollup;
        }

        return array_values($byMeter);
    }

    /**
     * Every owner that has reported usage — the owners worth asking the provider about. Each row is a
     * {@see UsageEvent} hydrated with only the two owner columns.
     *
     * @return EloquentCollection<int, UsageEvent>
     */
    private function ownersWithReportedUsage(): EloquentCollection
    {
        return UsageEvent::model()::query()
            ->where('is_rollup', true)
            ->where('state', UsageEventState::Reported->value)
            ->select(['owner_type', 'owner_id'])
            ->distinct()
            ->get();
    }

    /** The owner the reported usage stands in the name of, the one the flusher billed it to. */
    private function ownerOf(string $ownerType, mixed $ownerId): ?Model
    {
        return OwnerOfRecord::find($ownerType, $ownerId);
    }

    private function customerReference(Model $owner): ?string
    {
        $column = $this->config->get('billing.customer.column', 'stripe_id');
        $reference = $owner->getAttribute(is_string($column) ? $column : 'stripe_id');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /** How long the outbox may hold usage before it is a stall rather than an outage. */
    private function stallHours(): int
    {
        $hours = $this->config->get('billing.metering.stall_hours', 6);

        return is_int($hours) && $hours > 0 ? $hours : 6;
    }
}
