<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What happens when a metered dimension reaches its ceiling — the shared core renders the same
 * gauge for any of these, while the project's own enforcement path applies the rule:
 *
 *  - HardStop  the request is refused at the ceiling.
 *  - Degrade   the request still runs, but on a cheaper/slower path.
 *  - Refuse    a count-based hard limit.
 *  - FairUse   uncapped / soft — surfaced for visibility, never blocked.
 */
enum MeteringPolicy: string
{
    case HardStop = 'hard_stop';
    case Degrade = 'degrade';
    case Refuse = 'refuse';
    case FairUse = 'fair_use';

    /** Whether reaching the ceiling blocks further use (vs. degrades or is soft). */
    public function isBlocking(): bool
    {
        return $this === self::HardStop || $this === self::Refuse;
    }

    /**
     * The severity intent for the over-band callout: blocking policies read as danger, degrading as
     * warning, fair-use as neutral (informational only).
     */
    public function overBandIntent(): string
    {
        return match ($this) {
            self::HardStop, self::Refuse => 'danger',
            self::Degrade => 'warning',
            self::FairUse => 'neutral',
        };
    }

    /**
     * The remedy the usage screen offers a dimension that is running out. A blocking policy (hard stop /
     * refuse) refuses further use at the ceiling, so the only relief is a plan with a higher ceiling — an
     * `upgrade`. A degrading or fair-use policy keeps running, so the fix is buying more of the same unit
     * without changing plan — a `topup`. Drives which call-to-action renders beside the meter.
     */
    public function overRemedy(): string
    {
        return $this->isBlocking() ? 'upgrade' : 'topup';
    }
}
