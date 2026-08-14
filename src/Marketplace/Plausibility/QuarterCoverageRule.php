<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\Marketplace\MerchantChargeAnnualEarningsCounter;
use Pushery\Billing\Marketplace\SettlementGrossInflowCounter;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * The four quarters have to add up to the year — asked of the counters, not of the report.
 *
 * ## Why this is not circular
 *
 * The report's quarters come FROM these counters, so comparing them against the counters again would prove
 * nothing. What is compared here is the same counter asked a DIFFERENT question: four quarterly windows
 * against one annual window. Those are two independent walks over the same documents, and they can
 * disagree.
 *
 * They disagree exactly when a document lands inside the year and outside all four quarters, which is not
 * hypothetical: a correction is placed by the window rule (`ReversalAttribution`), so it can be attributed
 * to a date the quarterly walk resolves differently from the annual one. Whatever the cause, a filing whose
 * quarters do not sum to its year states one number four times and a different one once, and a reader
 * cannot tell which is the claim.
 *
 * ## Why the currency comparison is not just an amount comparison
 *
 * {@see Money} refuses to compare across currencies, which is what makes a mismatched-currency figure a
 * loud failure here rather than a silent zero.
 */
final readonly class QuarterCoverageRule implements ReportingPlausibilityRule
{
    public function __construct(
        private SettlementGrossInflowCounter $inflow,
        private MerchantChargeAnnualEarningsCounter $earnings,
    ) {}

    public function key(): string
    {
        return 'quarters_do_not_sum_to_the_year';
    }

    public function evaluate(array $reports, int $year, string $currency): array
    {
        $findings = [];
        $window = CountingPeriod::year($year);

        foreach ($reports as $report) {
            $mismatches = [];

            $quarterlyGross = Money::zero($currency);
            $quarterlyFees = Money::zero($currency);
            $quarterlyCount = 0;

            foreach ($report->quarters as $figures) {
                $quarterlyGross = $quarterlyGross->plus($figures->grossInflow);
                $quarterlyFees = $quarterlyFees->plus($figures->feesWithheld);
                $quarterlyCount += $figures->transactions;
            }

            $annualGross = $this->inflow->countedIn($report->seller, $currency, $window);
            $annualFees = $this->earnings->feesWithheldIn($report->seller, $currency, $window);
            $annualCount = $this->inflow->transactionsIn($report->seller, $currency, $window);

            if ($quarterlyGross->minorUnits !== $annualGross->minorUnits) {
                $mismatches[] = 'gross inflow '.$quarterlyGross->minorUnits.' vs '.$annualGross->minorUnits;
            }

            if ($quarterlyFees->minorUnits !== $annualFees->minorUnits) {
                $mismatches[] = 'fees withheld '.$quarterlyFees->minorUnits.' vs '.$annualFees->minorUnits;
            }

            if ($quarterlyCount !== $annualCount) {
                $mismatches[] = 'transactions '.$quarterlyCount.' vs '.$annualCount;
            }

            if ($mismatches === []) {
                continue;
            }

            $findings[] = new PlausibilityFinding(
                rule: $this->key(),
                subject: UnclassifiedActivityRule::subjectOf($report->seller),
                detail: 'The four quarters do not add up to the year for this seller ('
                    .implode('; ', $mismatches).', in minor units). Something falls inside the year and '
                    .'outside every quarter — most often a correction placed by the attribution rule. The '
                    .'filing would state one figure four times and a different one once.',
            );
        }

        return $findings;
    }
}
