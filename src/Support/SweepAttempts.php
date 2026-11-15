<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Pushery\Billing\Exceptions\SweepIncomplete;
use Throwable;

/**
 * Runs the entries of a scheduled sweep one at a time, so an entry that throws holds up no other.
 *
 * A sweep dispatches an event or a notice per entry and marks the entry after it, and the application's listener or
 * notifier sends the mail. One that throws, because a transport refuses a recipient or a provider refuses a request,
 * would end the whole run: the entry stays unmarked, the next run fails on it again, and no entry behind it is ever
 * handled. Here the failure is reported through the application's exception handler, the entry keeps no mark and is
 * tried again on the next run, and the sweep goes on with the next entry.
 *
 * Once every entry was tried, {@see finish()} throws {@see SweepIncomplete} where one failed, so the run ends as a
 * failure and a scheduler hears of it.
 *
 * @internal
 */
final class SweepAttempts
{
    private int $handled = 0;

    private int $failed = 0;

    /**
     * Runs one entry, and reports and counts it where it throws.
     *
     * @param  Closure(): void  $entry
     * @return bool whether the entry ran through
     */
    public function attempt(Closure $entry): bool
    {
        try {
            $entry();
        } catch (Throwable $failure) {
            Container::getInstance()->make(ExceptionHandler::class)->report($failure);
            $this->failed++;

            return false;
        }

        $this->handled++;

        return true;
    }

    /**
     * Ends the sweep, as a failure where an entry threw.
     *
     * @throws SweepIncomplete
     */
    public function finish(): void
    {
        if ($this->failed > 0) {
            throw new SweepIncomplete($this->handled, $this->failed);
        }
    }
}
