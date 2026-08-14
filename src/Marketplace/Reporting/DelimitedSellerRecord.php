<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Reporting;

use Pushery\Billing\Contracts\RendersReportingRecord;
use Pushery\Billing\Marketplace\Plausibility\UnclassifiedActivityRule;
use Pushery\Billing\Marketplace\SellerReportingPeriod;
use Pushery\Billing\ValueObjects\SellerQuarterFigures;

/**
 * The shipped record: one line per seller, four quarters each, as a delimited file.
 *
 * ## What this is and is not
 *
 * It is a complete, deterministic, archivable record of what a period says — the shape every bulk-filing
 * interface asks for, in the plainest carrier there is. It is NOT any particular authority's wire format,
 * and nothing here pretends to be: a real submission format is a schema with a namespace, and a package
 * that guessed at one would produce a file that validates nowhere.
 *
 * So this is the default a consumer gets for free and replaces when they have a schema to meet, which is
 * exactly what {@see RendersReportingRecord} exists for. The parts worth reusing are the ones that are hard
 * to get right and easy to get wrong quietly: the ordering, the number format, and the fact that an
 * unclassified seller cannot be rendered at all.
 *
 * ## Determinism, and the three ways it is usually lost
 *
 * The archive compares runs by fingerprint, so equal input must give equal bytes. Three things would break
 * that and none of them looks like a bug:
 *
 * - **Order.** The reports arrive sorted by the seller's stored morph pair ({@see SellerReportingPeriod}),
 *   and that order is passed through untouched rather than re-sorted here on anything a database chose.
 * - **Numbers.** Amounts are written in MINOR UNITS as integers. A decimal rendering would depend on the
 *   locale, and a float would depend on the platform.
 * - **Time.** Nothing here reads a clock. A generated-at stamp inside the payload would make every run
 *   differ from every other, which is the same as having no comparison at all.
 *
 * ## An unclassified seller is refused, not rendered
 *
 * Reaching this class means the plausibility step passed or its findings were answered. If a report still
 * carries an undecided line, `reportable()` throws — and that refusal is left to propagate. Rendering it
 * either way would file a decision nobody made.
 */
final readonly class DelimitedSellerRecord implements RendersReportingRecord
{
    /** The columns, in order. Written as a header so the file is self-describing rather than positional. */
    private const array COLUMNS = [
        'year', 'currency', 'seller', 'reportable',
        'q1_gross_minor', 'q1_transactions', 'q1_fees_minor',
        'q2_gross_minor', 'q2_transactions', 'q2_fees_minor',
        'q3_gross_minor', 'q3_transactions', 'q3_fees_minor',
        'q4_gross_minor', 'q4_transactions', 'q4_fees_minor',
    ];

    public function format(): string
    {
        return 'delimited-seller-record';
    }

    /**
     * A date rather than a number, because a format version is a moment in a statute rather than a release
     * of this package. Two consumers on different package versions can be on the same format, and a reader
     * years later needs to know which rules the bytes were built to — not which tag produced them.
     */
    public function version(): string
    {
        return '2026-08';
    }

    public function render(int $year, string $currency, array $reports, array $records): string
    {
        $rows = [implode(';', self::COLUMNS)];

        foreach ($reports as $report) {
            $subject = UnclassifiedActivityRule::subjectOf($report->seller);
            $fields = [
                (string) $year,
                strtoupper($currency),
                $subject,
                // Throws on an undecided line, and the refusal is deliberately not caught. A record that
                // rendered "0" there would file a decision nobody made.
                $report->reportable() ? '1' : '0',
            ];

            foreach ([1, 2, 3, 4] as $quarter) {
                $figures = $report->quarters[$quarter] ?? null;

                // Every quarter is written, including the empty ones — and including a quarter the report
                // does not carry at all. A record holding only the quarters with movement leaves a reader to
                // decide whether a missing one is a zero or an omission, and those are different statements
                // to an authority.
                $fields[] = $figures instanceof SellerQuarterFigures ? (string) $figures->grossInflow->minorUnits : '0';
                $fields[] = $figures instanceof SellerQuarterFigures ? (string) $figures->transactions : '0';
                $fields[] = $figures instanceof SellerQuarterFigures ? (string) $figures->feesWithheld->minorUnits : '0';
            }

            $rows[] = implode(';', $fields);
        }

        // A trailing newline, so appending or concatenating a file never joins two rows into one.
        return implode("\n", $rows)."\n";
    }
}
