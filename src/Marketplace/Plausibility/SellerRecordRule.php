<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace\Plausibility;

use LogicException;
use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\Contracts\SuppliesSellerRecords;
use Pushery\Billing\Marketplace\SellerRecordCompleteness;
use Pushery\Billing\ValueObjects\PlausibilityFinding;

/**
 * Every reportable seller's record is complete and its identifiers hold their own checks.
 *
 * Both halves come from {@see SellerRecordCompleteness}, which already knows them: which fields the active
 * profile requires, and whether an identifier passes its check digit. Nothing about Germany appears here —
 * the field catalog is the profile's, so a consumer under another duty gets their fields by binding another
 * profile rather than by switching this off.
 *
 * ## Why an absent record source is a finding and not a skip
 *
 * The values live in the consuming application ({@see SuppliesSellerRecords}), and a package that ran this
 * check with nowhere to read them would have exactly two options: report that everything is in order, or
 * say so. The first is the dangerous one — a filing cannot be assembled without the records, so "we could
 * not look" and "we looked and it was fine" must not produce the same answer.
 *
 * It is raised ONCE, about the period rather than about each seller: it is one piece of missing wiring, and
 * a hundred copies of it would bury the findings that are about actual sellers.
 *
 * ## Why only reportable sellers
 *
 * The field basis turns on it. A seller who is not reportable this year is not asked for the identifiers a
 * statute names, and demanding them anyway would be collecting data no law entitles anybody to. That is why
 * `reportable` is asked per LINE and any true answer counts: the fields follow the strictest line.
 */
final readonly class SellerRecordRule implements ReportingPlausibilityRule
{
    public function __construct(
        private SellerRecordCompleteness $completeness,
        private ?SuppliesSellerRecords $records = null,
    ) {}

    public function key(): string
    {
        return 'seller_record_incomplete';
    }

    public function evaluate(array $reports, int $year, string $currency): array
    {
        if (! $this->records instanceof SuppliesSellerRecords) {
            return [new PlausibilityFinding(
                rule: 'no_seller_record_source',
                subject: '',
                detail: 'No '.SuppliesSellerRecords::class.' is bound, so the sellers\' records could not be '
                    .'read at all. This is not "the records are fine" — nothing was checked, and a filing '
                    .'cannot be assembled without them.',
            )];
        }

        $findings = [];

        foreach ($reports as $report) {
            // An unclassified line makes reportability undecided and this predicate throws. Skipped rather
            // than reported here: `UnclassifiedActivityRule` already names that seller, and a second finding
            // saying "and we could not check their record either" is a consequence, not a fact to work on.
            try {
                $reportable = $report->reportable();
            } catch (LogicException) {
                continue;
            }

            if (! $reportable) {
                continue;
            }

            $isLegalEntity = $this->records->isLegalEntity($report->seller);
            $missing = $this->completeness->missingRequired(
                $this->records->valuesFor($report->seller),
                $isLegalEntity,
                reportable: true,
            );

            if ($missing === []) {
                continue;
            }

            $findings[] = new PlausibilityFinding(
                rule: $this->key(),
                subject: UnclassifiedActivityRule::subjectOf($report->seller),
                detail: 'This seller must be reported and their record is not filable. Missing or malformed: '
                    .implode(', ', $missing).'. A field listed here may be present but failing its own check '
                    .'— an identifier whose check digit does not hold is wrong now, not at the deadline.',
            );
        }

        return $findings;
    }
}
