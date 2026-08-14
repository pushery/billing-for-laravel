<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Exceptions\ReportingNotPlausible;
use Pushery\Billing\Models\ReportingFindingAcknowledgement;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * The § 18 check, as a step of its own that runs BEFORE anything is produced.
 *
 * ## Why a step rather than validation inside the export
 *
 * The duty is to check before reporting, and the difference between the two placements shows up at the
 * moment of a failure. Validation inside an export aborts once numbers have been drawn and files written:
 * the operator is left with a half-finished run and artifacts that must not be kept, and the list of what
 * is actually wrong arrives one item at a time, because the first failure stops the rest.
 *
 * This produces nothing. It runs every rule, returns every finding, and refuses once — so the answer is a
 * list to work through rather than a mess to clean up.
 *
 * The nearest thing in the package is the go-live checklist, and the difference is deliberate: a checkpoint
 * advises, this blocks.
 *
 * ## What an acknowledgement is and is not
 *
 * It clears ONE finding, in ONE period, with a reason and a name attached. It does not switch a rule off:
 * the same finding next year has a different key, so nobody inherits last year's judgement. That is the
 * property {@see ReportingFindingAcknowledgement} exists to hold, and the reason the period is in its
 * unique key rather than merely stored beside it.
 */
final readonly class ReportingPlausibilityGate
{
    public function __construct(
        private SellerReportingPeriod $period,
        private ReportingPlausibilityRules $rules,
    ) {}

    /**
     * Every finding the catalog raises about this period, acknowledged or not.
     *
     * What an operator reads to see the whole picture, including what has already been answered — a report
     * that hid acknowledged findings would make a period look cleaner every time somebody waved one
     * through.
     *
     * @return list<PlausibilityFinding>
     */
    public function findingsFor(int $year, string $currency): array
    {
        $reports = $this->period->reportsFor($year, $currency);
        $findings = [];

        foreach ($this->rules->all() as $rule) {
            foreach ($rule->evaluate($reports, $year, $currency) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * The findings still standing in the way, in the catalog's order.
     *
     * @return list<PlausibilityFinding>
     */
    public function openFindingsFor(int $year, string $currency): array
    {
        $answered = $this->acknowledgedKeys($year, $currency);

        return array_values(array_filter(
            $this->findingsFor($year, $currency),
            static fn (PlausibilityFinding $finding): bool => ! in_array($finding->key(), $answered, true),
        ));
    }

    /**
     * Refuse the period unless every finding has been resolved or answered.
     *
     * The one call an export makes, and it makes it FIRST. Named as an assertion rather than as a predicate
     * on purpose: a boolean invites a caller to look at it and carry on, and the whole point of the step is
     * that carrying on is not one of the options.
     *
     * @throws ReportingNotPlausible
     */
    public function assertClear(int $year, string $currency): void
    {
        $open = $this->openFindingsFor($year, $currency);

        if ($open !== []) {
            throw new ReportingNotPlausible($year, $currency, $open);
        }
    }

    /**
     * Answer one finding, for this period, on the record.
     *
     * Takes the finding rather than a bare key so the caller cannot answer something that was never raised:
     * a typo in a hand-written key produces a row that clears nothing, and the finding it was meant for
     * stays open with an acknowledgement sitting next to it that nobody can connect to anything.
     */
    public function acknowledge(
        int $year,
        string $currency,
        PlausibilityFinding $finding,
        string $by,
        string $reason,
    ): ReportingFindingAcknowledgement {
        return ReportingFindingAcknowledgement::query()->create([
            'period_year' => $year,
            'currency' => strtoupper($currency),
            'finding_key' => $finding->key(),
            'acknowledged_by' => $by,
            'reason' => $reason,
        ]);
    }

    /** @return list<string> */
    private function acknowledgedKeys(int $year, string $currency): array
    {
        return array_values(
            ReportingFindingAcknowledgement::query()
                ->where('period_year', $year)
                ->where('currency', strtoupper($currency))
                ->get()
                ->map(static fn (ReportingFindingAcknowledgement $row): string => $row->finding_key)
                ->all(),
        );
    }
}
