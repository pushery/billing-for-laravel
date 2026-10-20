<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\Contracts\TaxReturnPeriod;

/**
 * One month of the import scheme, the period its return is filed for.
 *
 * The import scheme's return is monthly and due by the end of the following month (Art. 369af VAT Directive),
 * where the one-stop-shop return is quarterly. A correction to a month is declared in a later return, as long as
 * the window counted from that month's due date allows. The quarter the month falls in is kept for the record of
 * a run, which files every return under its year and quarter and tells a month apart by its label.
 */
final readonly class ImportSchemePeriod implements TaxReturnPeriod
{
    /** The quarter the month falls in. */
    public int $quarter;

    public function __construct(
        public int $year,
        public int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("A month runs from 1 to 12; got {$month}.");
        }

        if ($year < 1_000 || $year > 9_999) {
            throw new InvalidArgumentException("A reporting year is four digits; got {$year}.");
        }

        $this->quarter = intdiv($month - 1, 3) + 1;
    }

    public static function containing(CarbonImmutable $moment): self
    {
        return new self($moment->year, $moment->month);
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::createStrict($this->year, $this->month, 1)->startOfDay();
    }

    public function endsOn(): CarbonImmutable
    {
        return $this->startsOn()->endOfMonth();
    }

    /**
     * The end of the month after this one.
     *
     * Counted forward from the month's start, two months less a day, never by adding a month to its last day: a
     * month that ends on the 31st would overflow into the month after next and land the due date a month late.
     */
    public function dueOn(): CarbonImmutable
    {
        return $this->startsOn()->addMonths(2)->subDay()->endOfDay();
    }

    public function correctableOn(CarbonImmutable $moment, int $windowYears): bool
    {
        return $moment->lessThanOrEqualTo($this->dueOn()->addYears($windowYears));
    }

    public function equals(TaxReturnPeriod $other): bool
    {
        return $other instanceof self && $this->year === $other->year && $this->month === $other->month;
    }

    /** The year and the month, `2026-06`, so a directory of files sorts into filing order. */
    public function label(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function periodContaining(CarbonImmutable $moment): self
    {
        return self::containing($moment);
    }
}
