<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The period one recapitulative statement covers: a quarter, or a single month.
 *
 * Most statements cover a quarter. A seller whose supplies of goods to businesses in other member states pass a
 * threshold reports each month instead, which Article 263 of Directive 2006/112/EC leaves to each member state to
 * set, so the period is a value of its own rather than the quarter every other return here uses.
 */
final readonly class RecapitulativeStatementPeriod
{
    private function __construct(
        public int $year,
        public int $quarter,
        public ?int $month,
    ) {}

    public static function quarter(ReportingPeriod $quarter): self
    {
        return new self($quarter->year, $quarter->quarter, null);
    }

    public static function month(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("A month runs from 1 to 12; got {$month}.");
        }

        // The quarter it falls in, which also holds the year to the four digits every period here has.
        $quarter = new ReportingPeriod($year, intdiv($month - 1, 3) + 1);

        return new self($quarter->year, $quarter->quarter, $month);
    }

    public function isMonthly(): bool
    {
        return $this->month !== null;
    }

    /** The quarter the period falls in, which is the period itself for a quarterly statement. */
    public function inQuarter(): ReportingPeriod
    {
        return new ReportingPeriod($this->year, $this->quarter);
    }

    public function startsOn(): CarbonImmutable
    {
        return $this->month === null
            ? $this->inQuarter()->startsOn()
            : CarbonImmutable::createStrict($this->year, $this->month, 1)->startOfDay();
    }

    public function endsOn(): CarbonImmutable
    {
        return $this->month === null
            ? $this->inQuarter()->endsOn()
            : $this->startsOn()->endOfMonth();
    }

    /** A stable label for an export line and a file name: `2026-Q1` for a quarter, `2026-03` for a month. */
    public function label(): string
    {
        return $this->month === null
            ? $this->inQuarter()->label()
            : sprintf('%04d-%02d', $this->year, $this->month);
    }
}
