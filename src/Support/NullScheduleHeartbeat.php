<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\ScheduleHeartbeat;

/**
 * The shipped default: no monitoring at all.
 *
 * A package cannot know where an install watches its jobs, and guessing would mean shipping an HTTP
 * client to ping a service nobody named. Doing nothing is the honest default — and it is visibly nothing,
 * rather than a half-configured integration that reports healthy because it was never reached.
 */
final readonly class NullScheduleHeartbeat implements ScheduleHeartbeat
{
    public function starting(string $command): void {}

    public function finished(string $command, bool $successful): void {}
}
