<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\FilingObligation;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * A filing obligation falls due soon, and this is the one warning it gets.
 *
 * ## One event per OBLIGATION, never per day
 *
 * The last period's return and the annual seller report fall due on the same date — different law, different
 * data, one calendar day. An event that said "something is due on the 31st" would let a recipient handle the
 * one they thought of and consider the day dealt with, which is exactly the failure the calendar exists to
 * prevent: file the thing you remembered, tick it off, and learn months later in a letter that the other one
 * was never sent.
 *
 * So each obligation carries its own event, and two arriving for one day is the correct and intended shape.
 *
 * ## It warns; nothing here files
 *
 * This package submits nothing to any authority and holds credentials for none. What it can do is make sure
 * the day does not arrive unannounced — and that being reminded about one obligation never counts as having
 * been reminded about the other.
 */
final readonly class FilingObligationApproaching
{
    public function __construct(
        public FilingObligation $obligation,
        public CarbonImmutable $dueOn,
        /** The period being filed for, or null for the annual seller report, which covers a year rather than one. */
        public ?ReportingPeriod $period,
        /** How many whole days remain, so a recipient can phrase its own urgency instead of recomputing it. */
        public int $daysRemaining,
    ) {}
}
