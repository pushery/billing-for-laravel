<?php

declare(strict_types=1);

namespace Pushery\Billing\Support\Concerns;

/**
 * How long a queued unit of this package waits before it is tried again.
 *
 * The worker's own default is no wait at all: every attempt of a unit that reaches a provider ran within the
 * same few seconds, and an outage of a minute used them all up, which left the unit failed until somebody
 * replayed it. The waits grow from ten seconds to fifteen minutes, so the attempts span about twenty minutes
 * of trouble at the provider before the last one.
 *
 * A method rather than a property, because Laravel reads a queued listener's options from an instance it builds
 * without its constructor, and asks a method for them where one exists.
 */
trait BacksOffBetweenAttempts
{
    /** @return list<int> seconds to wait before the second attempt, the third, the fourth, and every later one */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }
}
