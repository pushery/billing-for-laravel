<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\CheckpointStatus;

/**
 * What one checkpoint found, and why.
 *
 * The reason is mandatory in every direction, including a pass. An operator reading a preflight report is
 * deciding whether to switch a marketplace on; a bare green line tells them a check ran, not what it
 * established, and the difference matters most exactly when the line is wrong.
 */
final readonly class CheckpointOutcome
{
    private function __construct(
        public CheckpointStatus $status,
        public string $reason,
    ) {}

    public static function pass(string $reason): self
    {
        return new self(CheckpointStatus::Passed, $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(CheckpointStatus::Failed, $reason);
    }

    public static function warn(string $reason): self
    {
        return new self(CheckpointStatus::Warned, $reason);
    }
}
