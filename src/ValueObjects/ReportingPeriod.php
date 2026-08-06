<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One quarter of a year, as a tax return counts them.
 *
 * A period is not a date range that happens to be three months long: it has a DUE DATE, and the due date is
 * what every deadline in this area is measured from. Keeping the two together in one object is what stops a
 * caller measuring from the wrong one.
 */
final readonly class ReportingPeriod
{
    public function __construct(
        public int $year,
        public int $quarter,
    ) {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException("A quarter runs from 1 to 4; got {$quarter}.");
        }

        if ($year < 1_000 || $year > 9_999) {
            throw new InvalidArgumentException("A reporting year is four digits; got {$year}.");
        }
    }

    /** The period a moment falls in. */
    public static function containing(CarbonImmutable $moment): self
    {
        return new self($moment->year, (int) ceil($moment->month / 3));
    }

    public function startsOn(): CarbonImmutable
    {
        // Both fields were validated in the constructor, so this is always a real date — the fallback is for
        // the type system, not for a case that can occur.
        return CarbonImmutable::createStrict($this->year, ($this->quarter - 1) * 3 + 1, 1)->startOfDay();
    }

    public function endsOn(): CarbonImmutable
    {
        return $this->startsOn()->addMonths(3)->subDay()->endOfDay();
    }

    /**
     * When the return for this period has to be filed: the end of the month after it.
     *
     * This is the date every later deadline counts from, and the reason it is here rather than left to a
     * caller. The period ends a month earlier, and measuring a correction window from THAT moves the
     * boundary by exactly one month — in the direction that lets a correction through which is already out
     * of time.
     */
    public function dueOn(): CarbonImmutable
    {
        // Counted forward from the period's START, four months less a day — never by adding a month to its
        // last day. A quarter ends on the 31st as often as not, and adding a month to a 31st overflows into
        // the month after next, which lands the due date a full month late. That is the same one-month error
        // this class exists to keep out of the correction window, arrived at from the other side.
        return $this->startsOn()->addMonths(4)->subDay()->endOfDay();
    }

    /** Whether a correction to this period may still be declared, given a window in years. */
    public function correctableOn(CarbonImmutable $moment, int $windowYears): bool
    {
        return $moment->lessThanOrEqualTo($this->dueOn()->addYears($windowYears));
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->quarter === $other->quarter;
    }

    /** A stable label for an export line: the year and the quarter, in that order. */
    public function label(): string
    {
        return sprintf('%04d-Q%d', $this->year, $this->quarter);
    }
}
