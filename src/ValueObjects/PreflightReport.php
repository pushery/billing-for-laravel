<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\GoLiveStep;

/**
 * The result of one preflight run: every registered point, in checklist order, with its verdict.
 *
 * It also records which steps had NO points at all. That is not a detail: a stage printed as passed because
 * nothing was registered for it is a gate arm that cannot fire, and an arm that cannot fire reads exactly
 * like a check that passed. Keeping the empty stages nameable lets the report say "nothing is registered
 * here" in words instead of implying an all-clear.
 */
final readonly class PreflightReport
{
    /**
     * @param  list<PreflightLine>  $lines
     * @param  list<GoLiveStep>  $stepsWithoutCheckpoints
     */
    public function __construct(
        public array $lines,
        public array $stepsWithoutCheckpoints,
    ) {}

    /** Whether the marketplace switch may be flipped: no blocking point is failed or unreached. */
    public function passed(): bool
    {
        return $this->openBlockers() === [];
    }

    /**
     * The lines that stand between the operator and go-live.
     *
     * @return list<PreflightLine>
     */
    public function openBlockers(): array
    {
        return array_values(array_filter($this->lines, static fn (PreflightLine $line): bool => $line->isOpenBlocker()));
    }

    /**
     * The lines belonging to one step, in report order.
     *
     * @return list<PreflightLine>
     */
    public function linesFor(GoLiveStep $step): array
    {
        return array_values(array_filter($this->lines, static fn (PreflightLine $line): bool => $line->step === $step));
    }
}
