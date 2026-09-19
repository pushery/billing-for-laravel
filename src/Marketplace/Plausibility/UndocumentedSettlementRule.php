<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\Marketplace\WithheldFeeCounter;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * Which rows a quarter placed by their MONEY because no settlement document could place them.
 *
 * ## Why this is a finding rather than a footnote
 *
 * The two figures a return states side by side — the consideration credited to a seller and the fee withheld
 * out of it — are placed by the settlement document that credits them. A charge no document claims has no
 * such date, so it keeps the charge's own settlement date, which is what the package did everywhere before.
 *
 * That fallback is correct and it is not nothing. It means one line of the quarter was placed by a different
 * event from the rest, and a reader reconciling the quarter against the seller's own settlement documents
 * will not find it there. Reporting the fee anyway is deliberate: a fee nobody reports is as wrong as one
 * reported twice, and it is the quieter of the two.
 *
 * So the run says which rows those were. An operator either accepts the placement or raises the missing
 * document and re-runs — both are answers, and neither is available to somebody who was not told.
 *
 * ## Structural, like the four it joins
 *
 * It names no country, no threshold and no form. "A consideration has a date, and this row has none" holds
 * under any duty that reports per period.
 *
 * ## What an acknowledgement covers
 *
 * A finding's key is the rule and the subject; an acknowledgement also carries the PERIOD. So answering
 * this for a seller settles it for that period only, and a charge that is still undocumented next quarter
 * is reported again — which is the behavior worth having, because the second quarter is new information
 * about the same gap rather than a repeat of the first.
 *
 * ## Why the detail names a sample rather than every row
 *
 * A seller with thousands of undocumented charges would otherwise produce a detail nobody can read, in a
 * field meant for what an operator needs in order to act. The count is exact and the references are a
 * sample, said to be one — an operator who needs the full list asks the counter, which is the same call
 * this rule makes.
 */
final readonly class UndocumentedSettlementRule implements ReportingPlausibilityRule
{
    /** How many references a detail names before it says how many more there are. */
    private const int SAMPLE = 5;

    public function __construct(private WithheldFeeCounter $fees) {}

    public function key(): string
    {
        return 'settlement_document_does_not_place_this_charge';
    }

    public function evaluate(array $reports, int $year, string $currency): array
    {
        $findings = [];

        foreach ($reports as $report) {
            $perQuarter = [];
            $total = 0;
            $sample = [];

            for ($quarter = 1; $quarter <= 4; $quarter++) {
                $charges = $this->fees->chargesPlacedByTheirMoneyIn(
                    $report->seller,
                    $currency,
                    CountingPeriod::quarter($year, $quarter),
                );

                if ($charges->isEmpty()) {
                    continue;
                }

                $perQuarter[] = 'Q'.$quarter.': '.$charges->count();
                $total += $charges->count();

                foreach ($charges as $charge) {
                    if (count($sample) >= self::SAMPLE) {
                        break;
                    }

                    $sample[] = $charge->provider.'/'.$charge->charge_reference;
                }
            }

            if ($perQuarter === []) {
                continue;
            }

            $more = $total - count($sample);

            $findings[] = new PlausibilityFinding(
                rule: $this->key(),
                subject: UnclassifiedActivityRule::subjectOf($report->seller),
                detail: 'No settlement document claims '.$total.' of this seller\'s charges ('
                    .implode('; ', $perQuarter).'), so each was placed in a quarter by its own settlement '
                    .'date rather than by the document that credited it. The fee is still reported. Sample: '
                    .implode(', ', $sample).($more > 0 ? ' and '.$more.' more' : '')
                    .'. Either accept the placement or raise the missing document and re-run.',
            );
        }

        return $findings;
    }
}
