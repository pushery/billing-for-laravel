<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\ValueObjects\RecapitulativeStatementLine;
use Pushery\Billing\ValueObjects\RecapitulativeStatementPeriod;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * A period's recapitulative statement as a file somebody can report from.
 *
 * Neutral, like the one-stop-shop export beside it: the member state, the VAT id, the kind of supply and the
 * amount, one buyer per row, which is what every authority's form asks for in some layout of its own. The
 * amounts are the statement's own minor units written as decimals with their sign, never rounded or summed
 * again on the way out.
 */
final readonly class RecapitulativeStatementExport
{
    /** The columns, in order, written as a header so the file describes itself. */
    private const array COLUMNS = ['period', 'country', 'vat_id', 'kind', 'net'];

    /** @param  list<RecapitulativeStatementLine>  $lines */
    public function render(ReportingPeriod|RecapitulativeStatementPeriod $period, array $lines): string
    {
        $label = $period->label();
        $rows = [implode(';', self::COLUMNS)];

        foreach ($lines as $line) {
            $rows[] = implode(';', [
                $label,
                $line->country(),
                $line->vatId,
                $line->kind->value,
                $this->decimal($line->netMinor),
            ]);
        }

        // A trailing newline, so appending or concatenating a file never joins two rows into one.
        return implode("\n", $rows)."\n";
    }

    /** The suggested name for a period's file, the period first so a directory sorts into filing order. */
    public function filenameFor(ReportingPeriod|RecapitulativeStatementPeriod $period, string $currency): string
    {
        return sprintf('%s-recapitulative-statement-%s.csv', $period->label(), strtolower($currency));
    }

    /** Minor units as a decimal string, sign kept. */
    private function decimal(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }
}
