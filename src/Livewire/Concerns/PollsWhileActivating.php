<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire\Concerns;

/**
 * A bounded wire:poll for the live screens (overview, payment recovery). A transition state — the
 * post-checkout "activating" wait, a past-due recovery — needs the screen to refresh itself until the state
 * settles. This concern makes that a SHORT, self-limiting poll rather than a permanent one:
 *
 *  - it polls only WHILE the transition is active,
 *  - only up to a hard cap of ticks (~30s at 5s), then stops so a stuck state never polls forever.
 *
 * The poll is the RELIABLE refresh mechanism and runs whether or not realtime is enabled: realtime broadcasts
 * (`AccountToastNotified` → the `AccountRealtime` toast bridge) NOTIFY the owner, they do not re-render these
 * screens, so gating the poll on realtime would leave a transition with no refresh at all. The poll is
 * lightweight and bounded, so it is safe to keep on; an instant echo-driven `$refresh` is a possible future
 * enhancement, not a prerequisite for the screen settling.
 *
 * The view wraps the poll in `@if ($interval = $this->activationPoll($inTransition))` and targets
 * `activationTick`.
 */
trait PollsWhileActivating
{
    /** Ticks elapsed since polling began — the state survives across requests so the cap actually bounds it. */
    public int $activationTicks = 0;

    /** ~30 seconds at a 5s interval. After this the screen stops polling; the customer can reload if needed. */
    private const int ACTIVATION_POLL_CAP = 6;

    /** The wire:poll interval to use while the transition resolves, or null to stop polling. */
    public function activationPoll(bool $inTransition): ?string
    {
        if (! $inTransition || $this->activationTicks >= self::ACTIVATION_POLL_CAP) {
            return null;
        }

        return '5s';
    }

    /** Advance the bounded poll by one tick. Wire this to the poll target; a re-render re-reads the state. */
    public function activationTick(): void
    {
        $this->activationTicks++;
    }
}
