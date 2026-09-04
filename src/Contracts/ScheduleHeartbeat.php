<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Told when a scheduled billing command starts and how it ended, so an operator can notice one that stops.
 *
 * The failure this exists for is not a command that crashes — that is loud, and the log has it. It is a
 * command that stops RUNNING: a scheduler that was never installed on a new host, a `withoutOverlapping`
 * lock left behind by a killed process, a container that no longer starts the cron. Billing keeps
 * appearing to work, because everything except the sweep still does; what happens is that nobody is
 * charged, and the first report comes from the bank statement at the end of the month.
 *
 * Nothing catches that from the inside. The only thing that can is something OUTSIDE the process noticing
 * an expected signal did not arrive, which is why this is an interface and not an implementation: the
 * package would otherwise have to ship an HTTP client to ping a monitor, and it deliberately depends on
 * focused `illuminate/*` components rather than the framework.
 *
 * The shipped default does nothing. An install that wants monitoring binds its own — Nightwatch, Oh Dear,
 * Healthchecks.io, or a row in a table somebody looks at.
 */
interface ScheduleHeartbeat
{
    /** A scheduled billing command is about to run. */
    public function starting(string $command): void;

    /**
     * It finished.
     *
     * `$successful` reports the command's own exit status, which is not the same as "the work was done":
     * a sweep that processed nothing because the lock was held still exits zero. A monitor that only
     * watches for failures will not see the case this contract exists for — it has to watch for the
     * signal's ABSENCE.
     */
    public function finished(string $command, bool $successful): void;
}
