<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pushery\Billing\Events\CreatorReattestationDue;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

/**
 * Tells a creator that their tax declaration is due for renewal, before it runs out.
 *
 * A declaration answers until the year boundary plus the grace period, and then the creator is held:
 * no sales, no payouts, until they declare again. `LapsedAttestationSweep` announces the hold once it has
 * begun. This is the notice before it, and it comes twice:
 *
 * - **when the renewal falls due**, at the year boundary. The question is about the year that just ended,
 *   so it cannot be asked earlier, and the grace period is the time the creator has to answer it;
 * - **before the declaration runs out**, `billing.tax_small_business.reattestation.remind_days` ahead.
 *
 * Each goes out once per declaration. The markers live beside the series, like the hold announcement's:
 * they record the telling, not the standing. When both are due on the same run, only the last reminder goes
 * out and both are marked, because the second message would say the same thing a day later.
 *
 * Only a declaration that still answers is reminded. One that a later declaration has replaced needs no
 * renewal, one that has already run out belongs to the hold announcement, and a standing the system derived
 * itself has no expiry to remind anybody of.
 */
final readonly class ReattestationReminderSweep
{
    public function __construct(
        private Dispatcher $events,
        private Repository $config,
    ) {}

    /**
     * Remind every creator whose declaration is due or about to run out.
     *
     * @return int how many reminders went out
     */
    public function remind(CarbonImmutable $now): int
    {
        // In UTC, the zone the attestation columns hold: a binding is written in its own zone (see UtcDateTime).
        $at = Carbon::instance($now)->utc();
        $sent = 0;

        // The bounds of the two reminders below, as instants: the renewal is due once the year its expiry falls
        // in has begun, and the last reminder once the expiry is within the reminder window.
        $nextYear = $at->copy()->startOfYear()->addYear();
        $windowEnd = $at->copy()->addDays($this->remindDays());

        // Only the declarations a reminder is due for, so a daily run does not read every declaration in force
        // with its merchant to find the few that are. The loop below decides again from the same figures.
        $answering = CreatorTaxStatusRecord::model()::query()
            ->whereNotNull('attested_until')
            ->where('attested_until', '>', $at)
            ->where('effective_from', '<=', $at)
            ->where(static fn (Builder $pending): Builder => $pending
                ->where(static fn (Builder $due): Builder => $due
                    ->whereNull('reattestation_due_announced_at')
                    ->where('attested_until', '<', $nextYear))
                ->orWhere(static fn (Builder $expiring): Builder => $expiring
                    ->whereNull('expiry_reminded_at')
                    ->where('attested_until', '<=', $windowEnd)))
            ->with('merchant')
            ->lazyById(500);

        foreach ($answering as $record) {
            $holdFrom = $record->attested_until instanceof Carbon ? CarbonImmutable::instance($record->attested_until) : null;
            $due = $holdFrom instanceof CarbonImmutable && $record->reattestation_due_announced_at === null && $now->greaterThanOrEqualTo($this->dueFrom($holdFrom));
            $expiring = $holdFrom instanceof CarbonImmutable && $record->expiry_reminded_at === null && $now->greaterThanOrEqualTo($holdFrom->subDays($this->remindDays()));

            // The query returns only declarations a reminder is due for, and the row is asked the same question
            // again: one the query let through and this turns away gets no reminder and no mark. Whether the
            // declaration still governs costs a query of its own, so it is asked last.
            if (! $holdFrom instanceof CarbonImmutable || (! $due && ! $expiring) || ! $this->stillGoverns($record, $at)) {
                continue;
            }

            $merchant = $record->merchant;

            if ($merchant !== null) {
                $this->events->dispatch(new CreatorReattestationDue($merchant, $holdFrom, lastReminder: $expiring));
                $sent++;
            }

            // Marked after dispatching, as the hold announcement is: a crash between the two repeats one
            // reminder, the other order would lose it. A last reminder also stands in for a notice of the
            // due date that has not gone out yet, which would say the same thing a day later.
            $markers = ['reattestation_due_announced_at' => $record->reattestation_due_announced_at ?? $at];

            if ($expiring) {
                $markers['expiry_reminded_at'] = $at;
            }

            $record->forceFill($markers)->save();
        }

        return $sent;
    }

    /**
     * When the renewal falls due: the year boundary the declaration runs past.
     *
     * A declaration expires the grace period after that boundary, and the grace is shorter than a year, so
     * the boundary is the start of the year its expiry falls in.
     */
    private function dueFrom(CarbonImmutable $holdFrom): CarbonImmutable
    {
        return $holdFrom->startOfYear();
    }

    private function remindDays(): int
    {
        $days = $this->config->get('billing.tax_small_business.reattestation.remind_days', 14);

        return is_int($days) && $days >= 0 ? $days : 14;
    }

    /** Whether no later interval for the same creator has taken over from this one by now. */
    private function stillGoverns(CreatorTaxStatusRecord $record, Carbon $at): bool
    {
        return ! CreatorTaxStatusRecord::model()::query()
            ->where('merchant_type', $record->merchant_type)
            ->where('merchant_id', $record->merchant_id)
            ->where('effective_from', '>', $record->effective_from)
            ->where('effective_from', '<=', $at)
            ->exists();
    }
}
