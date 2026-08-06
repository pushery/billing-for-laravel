<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveChecklist;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Enums\CheckpointStatus;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\PreflightLine;
use Pushery\Billing\ValueObjects\PreflightReport;

/**
 * Runs the go-live checklist: every registered point, in checklist order, with the ordering enforced.
 *
 * Enforcing the order is the part that does real work. The stages are not a to-do list to be worked in any
 * sequence — publishing terms after the first sale is a different act from publishing them before it, and
 * so is registering with an authority. So a stage with an open blocking point does not merely fail: every
 * point AFTER it is reported unreachable, unevaluated, and explicitly not green. The alternative — showing
 * later stages as passing while an earlier one is open — invites exactly the sequence the checklist exists
 * to impose.
 */
final readonly class MarketplacePreflight
{
    /** The synthetic point that reports a waiver list which does not do what its author thinks. */
    private const string WAIVER_INTEGRITY_KEY = 'configuration.waivers';

    public function __construct(
        private GoLiveChecklist $checklist,
        private Repository $config,
    ) {}

    public function run(): PreflightReport
    {
        $checkpoints = $this->checklist->all();
        $waived = $this->waivedKeys();
        $integrity = $this->waiverIntegrityLine($checkpoints, $waived);

        $lines = [];
        $empty = [];
        $blockedBy = null;

        foreach (GoLiveStep::ordered() as $step) {
            $stepCheckpoints = $this->checkpointsFor($checkpoints, $step);

            if ($stepCheckpoints === []) {
                $empty[] = $step;
            }

            $stepLines = [];

            // The waiver-integrity line is always evaluated, never reported unreachable. It explains why a
            // waiver did not apply, and that explanation is needed most in the run where something earlier
            // already failed — quite possibly the very point the waiver was meant to cover.
            if ($step === GoLiveStep::Configuration && $integrity instanceof PreflightLine) {
                $stepLines[] = $integrity;
            }

            foreach ($stepCheckpoints as $checkpoint) {
                $stepLines[] = $blockedBy instanceof GoLiveStep
                    ? $this->unreachable($checkpoint, $blockedBy)
                    : $this->evaluate($checkpoint, $waived);
            }

            $lines = [...$lines, ...$stepLines];

            if (! $blockedBy instanceof GoLiveStep && $this->hasOpenBlocker($stepLines)) {
                $blockedBy = $step;
            }
        }

        return new PreflightReport($lines, $empty);
    }

    /**
     * The points of one step, ordered by key so two runs of the same configuration read identically.
     *
     * @param  list<GoLiveCheckpoint>  $checkpoints
     * @return list<GoLiveCheckpoint>
     */
    private function checkpointsFor(array $checkpoints, GoLiveStep $step): array
    {
        $matching = array_values(array_filter(
            $checkpoints,
            static fn (GoLiveCheckpoint $checkpoint): bool => $checkpoint->step() === $step,
        ));

        usort($matching, static fn (GoLiveCheckpoint $a, GoLiveCheckpoint $b): int => $a->key() <=> $b->key());

        return $matching;
    }

    /**
     * Evaluate one point, honoring a deliberate waiver.
     *
     * A waived point is still evaluated and still prints why it did not hold. It is demoted from a failure
     * to a warning, never to a pass: the operator chose to proceed without it, which is a different fact
     * from the point being satisfied, and the report has to keep saying which of the two happened.
     *
     * @param  list<string>  $waived
     */
    private function evaluate(GoLiveCheckpoint $checkpoint, array $waived): PreflightLine
    {
        $outcome = $checkpoint->evaluate();
        $isWaived = $checkpoint->isWaivable() && in_array($checkpoint->key(), $waived, true);

        if ($isWaived && $outcome->status === CheckpointStatus::Failed) {
            return new PreflightLine(
                $checkpoint->key(),
                $checkpoint->step(),
                $checkpoint->isBlocking(),
                CheckpointStatus::Warned,
                'Waived in billing.marketplace.preflight.waived, so it does not stop the go-live. It is NOT '.
                'satisfied: '.$outcome->reason,
            );
        }

        return new PreflightLine(
            $checkpoint->key(),
            $checkpoint->step(),
            $checkpoint->isBlocking(),
            $outcome->status,
            $outcome->reason,
        );
    }

    private function unreachable(GoLiveCheckpoint $checkpoint, GoLiveStep $blockedBy): PreflightLine
    {
        return new PreflightLine(
            $checkpoint->key(),
            $checkpoint->step(),
            $checkpoint->isBlocking(),
            CheckpointStatus::Unreachable,
            "Not evaluated: the earlier stage '{$blockedBy->label()}' still has an open blocking point.",
        );
    }

    /**
     * A waiver list that names something it cannot waive is a blocking failure, not a shrug.
     *
     * Both cases it catches look identical from the operator's chair — the point keeps failing although its
     * key is in the list — and both have the same cause: someone believed a point was switched off when it
     * was not. Ignoring the entry silently would leave that belief in place.
     *
     * @param  list<GoLiveCheckpoint>  $checkpoints
     * @param  list<string>  $waived
     */
    private function waiverIntegrityLine(array $checkpoints, array $waived): ?PreflightLine
    {
        $waivable = [];
        $known = [];

        foreach ($checkpoints as $checkpoint) {
            $known[] = $checkpoint->key();

            if ($checkpoint->isWaivable()) {
                $waivable[] = $checkpoint->key();
            }
        }

        $unknown = array_values(array_diff($waived, $known));
        $notWaivable = array_values(array_diff($waived, $waivable, $unknown));

        if ($unknown === [] && $notWaivable === []) {
            return null;
        }

        $problems = [];

        if ($unknown !== []) {
            $problems[] = 'no checkpoint is registered under '.implode(', ', $unknown);
        }

        if ($notWaivable !== []) {
            $problems[] = implode(', ', $notWaivable).' cannot be waived, because waiving would not make the '.
                'underlying condition true';
        }

        return new PreflightLine(
            self::WAIVER_INTEGRITY_KEY,
            GoLiveStep::Configuration,
            true,
            CheckpointStatus::Failed,
            'billing.marketplace.preflight.waived does not apply as written: '.implode('; ', $problems).
            '. Every entry that does not apply leaves its point fully in force.',
        );
    }

    /** @return list<string> */
    private function waivedKeys(): array
    {
        $waived = $this->config->get('billing.marketplace.preflight.waived', []);

        if (! is_array($waived)) {
            return [];
        }

        return array_values(array_unique(array_filter($waived, is_string(...))));
    }

    /** @param  list<PreflightLine>  $lines */
    private function hasOpenBlocker(array $lines): bool
    {
        return array_any($lines, fn (PreflightLine $line): bool => $line->isOpenBlocker());
    }
}
