<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\Marketplace\IntermediatedSalesCounter;
use Pushery\Billing\Marketplace\WithheldFeeCounter;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * Which chain sales no settlement document names, by the quarter their money moved.
 *
 * ## Why this is a finding rather than a footnote
 *
 * Under a commission chain the period reads what a seller received off the settlement documents, and only off
 * them. A charge carries the link to the document that settled it, but a collective settlement writes that
 * link only where its caller named the charge, and no caller did before the link existed. So a charge no
 * document names is one of two things, and nothing in the rows tells them apart: a sale a collective document
 * covers without naming it, which the period already counts, or a sale no document covers, which the period
 * leaves out although the seller was paid.
 *
 * Counting such a sale from the charge would settle the second case and double the first, and reporting more
 * than a seller received is no safer than reporting less. So the period counts documents, and this run names
 * the sales somebody has to look at: either a document covers each of them, or the missing document is raised
 * and the run repeated. Both are answers, and neither is available to somebody who was not told.
 *
 * ## A sale arranged as an intermediary is not one of them
 *
 * Under intermediation no settlement document is ever issued: the seller documents their own sale and the
 * platform invoices them its fee. Such a sale is dated by itself because that is the only date it has, not
 * because a document went missing, and telling an operator to raise one would ask for a document the
 * arrangement does not have.
 *
 * ## Structural, like the four it joins
 *
 * It names no country, no threshold and no form. "Money reached a seller, and no document names the sale"
 * holds under any duty that reports per period.
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

    public function __construct(
        private WithheldFeeCounter $fees,
        private IntermediatedSalesCounter $intermediated,
    ) {}

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
                )->reject(fn (MerchantCharge $charge): bool => $this->intermediated->arranged($charge));

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
                detail: 'No settlement document names '.$total.' of this seller\'s charges, by the quarter they '
                    .'were paid out in ('.implode('; ', $perQuarter).'). The period counts what a chain seller '
                    .'received from settlement documents alone, so each of these sales is in it only if a '
                    .'document covers it without naming it, as a collective settlement does when its run named '
                    .'no charges. Sample: '.implode(', ', $sample).($more > 0 ? ' and '.$more.' more' : '')
                    .'. Either confirm that a document covers each of them, or raise the missing document and re-run.',
            );
        }

        return $findings;
    }
}
