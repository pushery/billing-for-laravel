<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\UsageEventState;
use Pushery\Billing\Events\UsageBacklogStalled;
use Pushery\Billing\Models\UsageEvent;
use Pushery\Billing\Support\UsageReconciler;

/**
 * Answers "is any usage going to be mis-billed right now?" — the point-in-time check a scheduler runs and
 * a human runs after a provider incident. It looks in three places, because usage goes wrong in three ways:
 *
 *  - DRIFT: our ledger and the provider's meter disagree about how much a customer used. Reporting can
 *    succeed on our side and still never arrive, and the two sources drift quietly until the invoice. This
 *    reads the provider's own aggregate back and compares it against what we netted and sent.
 *  - A STALLED BACKLOG: usage the flusher has been holding, unreported, for longer than the provider's
 *    acceptance window allows — an outage that stopped being temporary and became lost revenue.
 *  - FAILED rollups: usage the flusher gave up on after exhausting its retries. `--redrive` resets them to
 *    pending so the next flush retries them — run it AFTER fixing the cause (a missing meter that
 *    `billing:meters:check` found, a restored provider).
 *
 * Drift and stall also dispatch domain events, so monitoring escalates in real time; the exit code is the
 * signal for whoever ran the command — non-zero when anything here needs a human.
 */
final class ReconcileUsageCommand extends Command
{
    protected $signature = 'billing:usage:reconcile {--redrive : Reset failed usage rollups to pending so the next flush retries them}';

    protected $description = 'Reconcile recorded usage against the provider and surface anything that will be mis-billed.';

    public function handle(UsageReconciler $reconciler): int
    {
        // Each check reports on its own and must run regardless of the others, so all three are evaluated
        // before they are combined — never short-circuited.
        $drift = $this->reportDrift($reconciler);
        $backlog = $this->reportBacklog($reconciler);
        $failed = $this->reportFailed();

        $problem = $drift || $backlog || $failed;

        if (! $problem) {
            $this->components->info('Recorded usage agrees with the provider; no drift, backlog or failed rollups.');
        }

        return $problem ? self::FAILURE : self::SUCCESS;
    }

    /** Compare our netted totals against the provider's own aggregate; true when any customer drifted. */
    private function reportDrift(UsageReconciler $reconciler): bool
    {
        $drifts = $reconciler->reconcile();

        foreach ($drifts as $drift) {
            $this->components->warn(sprintf(
                "Usage drift on meter '%s' (%s): we reported %d, the provider recorded %d (delta %+d).",
                $drift->meterKey,
                $drift->period,
                $drift->reported,
                $drift->recorded,
                $drift->delta(),
            ));
        }

        return $drifts !== [];
    }

    /** Fire and report the stall alarm when the oldest pending rollup is past its window; true when stalled. */
    private function reportBacklog(UsageReconciler $reconciler): bool
    {
        $stalled = $reconciler->checkBacklog();

        if (! $stalled instanceof UsageBacklogStalled) {
            return false;
        }

        $this->components->warn(sprintf(
            '%d usage rollup(s) totalling %d unit(s) have been unreported for %d hour(s) — past the point they can still be billed.',
            $stalled->pendingRollups,
            $stalled->pendingUnits,
            $stalled->stalledHours,
        ));

        return true;
    }

    /**
     * Report failed rollups and, with --redrive, reset them to pending. Returns true only when failed
     * rollups were left UNRESOLVED — a --redrive run has acted on them, so it hands the usage back to the
     * flusher and is not itself a lingering problem.
     */
    private function reportFailed(): bool
    {
        $failed = UsageEvent::query()->where('is_rollup', true)->where('state', UsageEventState::Failed->value);

        $count = (clone $failed)->count();

        if ($count === 0) {
            return false;
        }

        $quantity = (int) (clone $failed)->sum('quantity');

        $this->components->warn("{$count} usage rollup(s) totalling {$quantity} unit(s) failed to report — usage that will not be billed unless re-driven.");

        if ($this->option('redrive') !== true) {
            $this->line('Fix the cause first (see billing:meters:check), then re-run with --redrive to retry them.');

            return true;
        }

        $reset = (clone $failed)->update([
            'state' => UsageEventState::Pending->value,
            'attempts' => 0,
            'next_attempt_at' => Carbon::now(),
            'last_error' => null,
        ]);

        $this->components->info("Re-drove {$reset} failed rollup(s) back to pending; the next flush will retry them.");

        return false;
    }
}
