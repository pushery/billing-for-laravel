<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\ValueObjects\ReportingPeriod;
use Pushery\Billing\ValueObjects\TaxReturnLine;

/**
 * A period's return lines as a file somebody can actually file.
 *
 * ## The correction column is on every row, always
 *
 * A row correcting an earlier period and a row declaring this one carry the same figures in the same shape;
 * the only thing separating them is which period they belong to. So the column that says so is a **mandatory
 * part of every row**, empty where there is nothing to correct — not a column that appears when a correction
 * happens. A file whose shape depends on its contents is a file whose reader has to guess, and a correction
 * that lost its origin period is declared into the wrong quarter with nothing anywhere saying so.
 *
 * ## Figures come out exactly as they went in
 *
 * The amounts are the ones the return computed, written as decimal strings from integer minor units. Nothing
 * is re-rounded, re-summed or re-derived on the way out: a second arithmetic path is a second chance to
 * disagree with the documents this was all built from, and the disagreement would be cents nobody could
 * trace.
 *
 * A negative figure is written with its sign. A correction reduces, and hiding that behind a separate column
 * or an absolute value would make the file readable only by something that already knew the convention.
 */
final readonly class PeriodicTaxReturnExport
{
    /** The columns, in order. The header is written so the file is self-describing rather than positional. */
    private const array COLUMNS = [
        'period', 'country', 'category', 'rate_bps', 'net', 'tax', 'corrects_period',
    ];

    /**
     * One period's lines as a delimited file.
     *
     * @param  list<TaxReturnLine>  $lines
     */
    public function render(ReportingPeriod $period, array $lines): string
    {
        $rows = [implode(';', self::COLUMNS)];

        foreach ($lines as $line) {
            $rows[] = implode(';', [
                $period->label(),
                $line->country,
                $line->category->value,
                (string) $line->rateBps,
                $this->decimal($line->netMinor),
                $this->decimal($line->taxMinor),
                // Always present, empty when this row declares its own period's sales.
                $line->originPeriod?->label() ?? '',
            ]);
        }

        // A trailing newline, so appending or concatenating a file never joins two rows into one.
        return implode("\n", $rows)."\n";
    }

    /**
     * The suggested name for a period's file — the period first, so a directory sorts into filing order.
     */
    public function filenameFor(ReportingPeriod $period, string $currency): string
    {
        return sprintf('%s-tax-return-%s.csv', $period->label(), strtolower($currency));
    }

    /** Minor units as a decimal string, sign kept. */
    private function decimal(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }
}
