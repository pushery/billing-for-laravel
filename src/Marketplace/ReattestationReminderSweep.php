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
        $at = Carbon::instance($now);
        $sent = 0;

        $answering = CreatorTaxStatusRecord::model()::query()
            ->whereNotNull('attested_until')
            ->where('attested_until', '>', $at)
            ->where('effective_from', '<=', $at)
            ->where(static fn (Builder $pending): Builder => $pending
                ->whereNull('reattestation_due_announced_at')
                ->orWhereNull('expiry_reminded_at'))
            ->with('merchant')
            ->orderBy('id')
            ->get();

        foreach ($answering as $record) {
            if (! $this->stillGoverns($record, $at) || ! $record->attested_until instanceof Carbon) {
                continue;
            }

            $holdFrom = CarbonImmutable::instance($record->attested_until);
            $due = $record->reattestation_due_announced_at === null && $now->greaterThanOrEqualTo($this->dueFrom($holdFrom));
            $expiring = $record->expiry_reminded_at === null && $now->greaterThanOrEqualTo($holdFrom->subDays($this->remindDays()));

            if (! $due && ! $expiring) {
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
