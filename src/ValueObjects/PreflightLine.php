<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\CheckpointStatus;
use Pushery\Billing\Enums\GoLiveStep;

/**
 * One line of a preflight report: what the point was, whether it had to hold, and what came of it.
 *
 * It carries the checkpoint's own identity rather than a rendered string, so the report can be printed,
 * asserted against, or quoted in a boot refusal without any of the three re-deriving the others.
 */
final readonly class PreflightLine
{
    public function __construct(
        public string $key,
        public GoLiveStep $step,
        public bool $blocking,
        public CheckpointStatus $status,
        public string $reason,
    ) {}

    /** Whether this line stops the go-live: a blocking point that did not hold, or that was never reached. */
    public function isOpenBlocker(): bool
    {
        return $this->blocking && ! $this->status->isSatisfied();
    }
}
