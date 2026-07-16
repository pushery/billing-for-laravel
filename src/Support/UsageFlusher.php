<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Pushery\Billing\Contracts\UsageReporter;
use Pushery\Billing\Enums\UsageEventState;
use Pushery\Billing\Models\UsageEvent;
use Throwable;

/**
 * Hands the usage outbox to the provider that bills it.
 *
 * The ordering is the whole design, and it is: COALESCE, then report, then mark. Never report first.
 *
 *  1. In one transaction, a cycle's pending usage for one owner and one meter is folded into a single
 *     ROLLUP event carrying its own identifier and the sum of the units. The sources point at it.
 *  2. Outside any transaction, the rollup is reported.
 *  3. In one transaction, the rollup and its sources are marked reported.
 *
 * A crash between 2 and 3 replays the same identifier, which the provider dedups — the usage is billed
 * once. A crash between 1 and 2 leaves a pending rollup the next run picks up. There is no order in
 * which a unit is billed twice, and none in which one silently disappears.
 *
 * A rollup is never folded into another rollup. It may already have reached the provider under its own
 * identifier while our write failed, and re-reporting those units under a fresh identifier is precisely
 * how a customer is charged twice.
 *
 * A provider outage is not a crash: rows stay pending, back off, and are retried. What is NOT tolerated
 * is silence — past the retry budget the event is marked failed and logged as what it is: revenue that
 * will not be collected unless someone acts.
 */
final readonly class UsageFlusher
{
    public function __construct(
        private UsageReporter $reporter,
        private Repository $config,
        private LoggerInterface $log,
    ) {}

    /** Flush every rollup that is due. Returns how many were reported. */
    public function flush(): int
    {
        $this->coalesce();

        $reported = 0;

        foreach ($this->dueRollups() as $rollup) {
            if ($this->report($rollup)) {
                $reported++;
            }
        }

        return $reported;
    }

    /**
     * Force ONE owner's pending usage to the provider NOW — backoff and the scheduled cadence ignored.
     *
     * Called when the provider signals a customer's next invoice is about to finalize: any usage still in
     * the outbox has to land on THAT invoice, and waiting for the next scheduled flush (or for a rollup's
     * retry backoff to elapse) would bill it a cycle late. Scoped to the one owner so an upcoming-invoice
     * signal for one customer never drains everyone else's outbox. Returns how many rollups were reported.
     */
    public function flushOwner(Model $owner): int
    {
        $ownerType = $owner->getMorphClass();
        $ownerId = $owner->getKey();

        $this->coalesce($ownerType, $ownerId);

        $reported = 0;

        foreach ($this->pendingRollupsFor($ownerType, $ownerId) as $rollup) {
            if ($this->report($rollup)) {
                $reported++;
            }
        }

        return $reported;
    }

    /**
     * Fold each owner+meter+period's un-rolled pending usage into one rollup event. Scoped to a single
     * owner when one is given (a force-flush), otherwise every owner (the scheduled flush).
     */
    private function coalesce(?string $ownerType = null, mixed $ownerId = null): void
    {
        $groups = UsageEvent::query()
            ->where('state', UsageEventState::Pending->value)
            ->where('is_rollup', false)
            ->whereNull('rolled_up_into')
            ->when($ownerType !== null, fn (Builder $query): Builder => $query->where('owner_type', $ownerType)->where('owner_id', $ownerId))
            ->select(['owner_type', 'owner_id', 'meter_key', 'period'])
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            DB::transaction(function () use ($group): void {
                $sources = UsageEvent::query()
                    ->where('owner_type', $group->owner_type)
                    ->where('owner_id', $group->owner_id)
                    ->where('meter_key', $group->meter_key)
                    ->where('period', $group->period)
                    ->where('state', UsageEventState::Pending->value)
                    ->where('is_rollup', false)
                    ->whereNull('rolled_up_into')
                    ->lockForUpdate()
                    ->get();

                if ($sources->isEmpty()) {
                    return; // another worker got there first
                }

                $rollup = UsageEvent::query()->create([
                    'owner_type' => $group->owner_type,
                    'owner_id' => $group->owner_id,
                    'meter_key' => $group->meter_key,
                    'provider_meter' => $sources->first()->provider_meter,
                    'quantity' => $sources->sum('quantity'),
                    // Carried up with the usage: what the owner's PREPAID units already covered. The
                    // provider must not bill it — see report().
                    'prepaid_units' => $sources->sum('prepaid_units'),
                    // The LATEST source's moment: the rollup must not claim usage happened earlier than
                    // it did, and every source in it belongs to the same cycle anyway.
                    'occurred_at' => $sources->max('occurred_at'),
                    'period' => $group->period,
                    'identifier' => (string) Str::ulid(),
                    'state' => UsageEventState::Pending->value,
                    'is_rollup' => true,
                    'attempts' => 0,
                ]);

                UsageEvent::query()
                    ->whereIn('id', $sources->pluck('id'))
                    ->update(['rolled_up_into' => $rollup->getKey(), 'updated_at' => now()]);
            });
        }
    }

    /** @return Collection<int, UsageEvent> */
    private function dueRollups(): Collection
    {
        return UsageEvent::query()
            ->where('state', UsageEventState::Pending->value)
            ->where('is_rollup', true)
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Every pending rollup for one owner, backoff IGNORED — the force-flush's queue. A rollup mid-backoff is
     * still reported here on purpose: the invoice is closing now, so the usage goes on it regardless of when
     * its next scheduled retry would have been.
     *
     * @return Collection<int, UsageEvent>
     */
    private function pendingRollupsFor(string $ownerType, mixed $ownerId): Collection
    {
        return UsageEvent::query()
            ->where('state', UsageEventState::Pending->value)
            ->where('is_rollup', true)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('id')
            ->get();
    }

    private function report(UsageEvent $rollup): bool
    {
        $meter = $rollup->provider_meter;
        $customer = $this->customerReference($rollup);

        if ($meter === null || $customer === null) {
            $this->fail($rollup, 'The usage has no provider meter or no provider customer to bill it to.');

            return false;
        }

        // NET THE PREPAID UNITS. The provider's price knows nothing about units the owner bought outright —
        // it only applies its own allowance to whatever we hand it. So we hand it the usage MINUS what
        // prepaid already covered, and its price then applies the tier's `included` on top. Reporting the
        // raw total would bill the customer for units they have already paid for.
        $billable = $rollup->quantity - $rollup->prepaid_units;

        if ($billable <= 0) {
            // Wholly covered by prepaid. There is nothing for the provider to bill, and a zero meter event
            // is not a thing to send — the usage is settled, so mark it so.
            $this->settleReported($rollup);

            return true;
        }

        try {
            $this->reporter->report($customer, $meter, $billable, $rollup->identifier, $rollup->occurred_at);
        } catch (Throwable $e) {
            $this->retryOrFail($rollup, $e->getMessage());

            return false;
        }

        $this->settleReported($rollup);

        return true;
    }

    /** Mark a rollup and the events folded into it as reported — the provider has it, or owes nothing for it. */
    private function settleReported(UsageEvent $rollup): void
    {
        DB::transaction(function () use ($rollup): void {
            $rollup->forceFill([
                'state' => UsageEventState::Reported,
                'reported_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            UsageEvent::query()
                ->where('rolled_up_into', $rollup->getKey())
                ->update(['state' => UsageEventState::Reported->value, 'reported_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Back off and try again, until the budget runs out — at which point it is a loss, not a retry. */
    private function retryOrFail(UsageEvent $rollup, string $error): void
    {
        $attempts = $rollup->attempts + 1;

        if ($attempts >= $this->maxAttempts()) {
            $this->fail($rollup, $error, $attempts);

            return;
        }

        $rollup->forceFill([
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => Carbon::now()->addSeconds($this->backoffSeconds() * 2 ** ($attempts - 1)),
        ])->save();

        $this->log->warning('Usage report failed; will retry.', [
            'identifier' => $rollup->identifier,
            'meter' => $rollup->meter_key,
            'attempts' => $attempts,
            'reason' => $error,
        ]);
    }

    private function fail(UsageEvent $rollup, string $error, int $attempts = 0): void
    {
        DB::transaction(function () use ($rollup, $error, $attempts): void {
            $rollup->forceFill([
                'state' => UsageEventState::Failed,
                'attempts' => max($attempts, $rollup->attempts),
                'last_error' => $error,
            ])->save();

            UsageEvent::query()
                ->where('rolled_up_into', $rollup->getKey())
                ->update(['state' => UsageEventState::Failed->value, 'updated_at' => now()]);
        });

        // Loud on purpose: this is usage a customer incurred and will not be billed for.
        $this->log->error('Usage could not be reported and will NOT be billed.', [
            'identifier' => $rollup->identifier,
            'meter' => $rollup->meter_key,
            'quantity' => $rollup->quantity,
            'period' => $rollup->period,
            'owner' => $rollup->owner_type.':'.$rollup->owner_id,
            'reason' => $error,
        ]);
    }

    /** The owner's provider customer reference (Cashier's `stripe_id` by default), or null. */
    private function customerReference(UsageEvent $rollup): ?string
    {
        $class = Relation::getMorphedModel($rollup->owner_type) ?? $rollup->owner_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($rollup->owner_id);

        if (! $owner instanceof Model) {
            return null;
        }

        $column = $this->config->get('billing.customer.column', 'stripe_id');
        $reference = $owner->getAttribute(is_string($column) ? $column : 'stripe_id');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    private function maxAttempts(): int
    {
        $value = $this->config->get('billing.metering.max_attempts', 8);

        return is_int($value) && $value > 0 ? $value : 8;
    }

    private function backoffSeconds(): int
    {
        $value = $this->config->get('billing.metering.backoff_seconds', 60);

        return is_int($value) && $value > 0 ? $value : 60;
    }
}
