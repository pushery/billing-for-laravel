<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * Compares the rates this package ships against what a source says they are.
 *
 * Pure: it is handed the rows a source returned and the date that source claims to be answering for, and it
 * decides. Fetching lives with the caller, which is what lets every case below be tested without a network —
 * and a conformity check whose own tests need the internet is one that will eventually be run without them.
 *
 * The window check is applied here rather than left to the fetcher because it is a **comparison** concern,
 * not a transport one: a response is only evidence about the moment it actually answers for. A source that
 * quietly answers for today when asked about next year is not unreachable and is not disagreeing — it simply
 * has not answered the question, and reporting anything else about it would be an invention.
 */
final readonly class RateConformityProbe
{
    /**
     * @param  list<array{memberState: string, type: string, rateType: string, value: float, comment?: string}>  $rows
     * @param  array<string, int>|null  $shipped  the rates to check, in basis points; defaults to the package's own
     */
    public function compare(
        array $rows,
        string $situationOn,
        CarbonImmutable $askedFrom,
        CarbonImmutable $askedTo,
        ?array $shipped = null,
    ): RateConformityReport {
        // Asked about one window, answered about another: no evidence either way. Treated as unreachable
        // rather than as agreement, because agreement is a claim and this response supports none.
        if (! TedbRateReduction::answersFor($situationOn, $askedFrom, $askedTo)) {
            return new RateConformityReport(unreachable: true, situationOn: $situationOn);
        }

        $reduced = TedbRateReduction::reduce($rows);
        $shipped ??= EuOssTaxCalculator::shippedRatesBps();

        $drift = [];
        $missing = [];

        foreach ($shipped as $country => $bps) {
            if (isset($reduced['refused'][$country])) {
                // The source gave two standard rates for this country. Nothing to compare against, and
                // calling it a drift would blame our table for the answer's ambiguity.
                continue;
            }

            if (! isset($reduced['rates'][$country])) {
                // A country we price and the source did not mention. Not a drift — we have no second
                // opinion — but worth naming, because a source that quietly stops covering a country looks
                // exactly like agreement.
                $missing[] = $country;

                continue;
            }

            if ($reduced['rates'][$country] !== $bps) {
                $drift[$country] = ['shipped' => $bps, 'source' => $reduced['rates'][$country]];
            }
        }

        return new RateConformityReport(
            drift: $drift,
            missingFromSource: $missing,
            refused: $reduced['refused'],
            situationOn: $situationOn,
        );
    }

    /** The report for a source that could not be reached at all. */
    public function unreachable(): RateConformityReport
    {
        return new RateConformityReport(unreachable: true);
    }
}
