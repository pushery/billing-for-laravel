<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * One thing that moved an owner's usage balance, at the granularity a person asks about.
 *
 * ## Why this exists next to PeriodUsage and AddonTopup
 *
 * Those two answer "how much did a period cost" and "when was credit bought". Neither answers the
 * question a balance screen is opened for: *why was I out on the 14th when I topped up on the 12th?*
 * A period is one row for a month that may hold five top-ups and two hundred sends, and the ordering
 * between them — the part that explains the outcome — is exactly what aggregation removes.
 *
 * ## The delta is ONE signed number, deliberately
 *
 * An amount plus a direction enum can disagree: `amount: -5, direction: Topup` is representable and
 * means nothing, and every consumer would have to decide which half wins. A sign cannot contradict
 * itself. {@see units()} hands the magnitude to a view that wants to render an arrow beside a positive
 * number, so nothing downstream has to strip a minus.
 *
 * ## Zero and blank are refused at construction
 *
 * A movement of zero is not a movement, and a row without a reference is a period row with extra
 * steps — the reference IS the answer this granularity exists to give. Both would render, and both
 * would quietly make the timeline less trustworthy than the aggregate it replaced.
 */
final readonly class UsageMovement
{
    public function __construct(
        public CarbonInterface $occurredOn,
        /** The metered dimension this moved, matching {@see MeteredDimension::$key}. */
        public string $meter,
        /** Negative consumes, positive credits. Never zero. */
        public int $delta,
        /** Where it came from, in the project's own words — an order id, a campaign name, "monthly grant". */
        public string $reference,
    ) {
        if ($delta === 0) {
            throw new InvalidArgumentException(
                "A usage movement on '{$meter}' has a delta of zero. A row that moves nothing answers no "
                .'question and cannot be told apart from one that does.',
            );
        }

        if (trim($reference) === '') {
            throw new InvalidArgumentException(
                "A usage movement on '{$meter}' carries no reference. The reference is what this "
                .'granularity exists to provide — without it the row says less than the period total it '
                .'was meant to explain.',
            );
        }
    }

    /** Spent units rather than granted ones. */
    public function isConsumption(): bool
    {
        return $this->delta < 0;
    }

    /** Granted units rather than spent ones — a top-up, a monthly allowance, a correction. */
    public function isCredit(): bool
    {
        return $this->delta > 0;
    }

    /** How many units moved, without the direction. */
    public function units(): int
    {
        return abs($this->delta);
    }
}
