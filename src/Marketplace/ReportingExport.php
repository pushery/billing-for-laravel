<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\RendersReportingRecord;
use Pushery\Billing\Contracts\SuppliesSellerRecords;
use Pushery\Billing\Exceptions\ReportingNotPlausible;
use Pushery\Billing\Marketplace\Plausibility\UnclassifiedActivityRule;
use Pushery\Billing\Models\ReportingExportRecord;
use Pushery\Billing\Tax\FilingCalendar;
use Pushery\Billing\ValueObjects\SellerPeriodReport;

/**
 * Produces a period's reporting record — after the check, never instead of it.
 *
 * ## The order is the whole design
 *
 * `assertClear()` runs FIRST, before a byte is rendered or a row is written. The statutory check happens
 * before the report, and the difference between the two placements is what a failure costs: a check folded
 * into the rendering aborts once a file exists, leaving an operator holding an artifact they must not keep
 * and a list of problems that arrives one at a time. This produces nothing until the period is clear.
 *
 * That is also why the export does not merely DOCUMENT that a caller should check first. A step somebody
 * has to remember is a step that is skipped exactly when the period is messy — which is the period the
 * check exists for.
 *
 * ## It files nothing
 *
 * The package holds no portal credentials and transmits nothing. What comes back is a record and, if a disk
 * is configured, a file. The same line {@see FilingCalendar} draws: it warns, it does
 * not file.
 */
final readonly class ReportingExport
{
    public function __construct(
        private ReportingPlausibilityGate $gate,
        private SellerReportingPeriod $period,
        private ReportingExportArchive $archive,
        private RendersReportingRecord $renderer,
        /**
         * Where the sellers' own values come from, and null is a real state rather than a misconfiguration
         * to defend against here: the plausibility gate above already reports an unbound source as a
         * finding, so a period that reached the rendering has either been given one or had that finding
         * answered by somebody who knew what they were doing.
         */
        private ?SuppliesSellerRecords $records = null,
    ) {}

    /**
     * Check the period, render it, and keep the bytes.
     *
     * @throws ReportingNotPlausible while any finding about the period is unanswered — and nothing is
     *                               produced, which is the point of raising it here rather than later
     */
    public function produce(int $year, string $currency, ?CarbonInterface $at = null): ReportingExportRecord
    {
        $this->gate->assertClear($year, $currency);

        $reports = $this->period->reportsFor($year, $currency);

        return $this->archive->store(
            year: $year,
            currency: $currency,
            format: $this->renderer->format(),
            formatVersion: $this->renderer->version(),
            contents: $this->renderer->render($year, $currency, $reports, $this->valuesFor($reports)),
            sellerCount: count($reports),
            at: $at,
        );
    }

    /**
     * The sellers' own values, keyed the way a renderer can find them.
     *
     * Collected here rather than handed the source itself, so a renderer cannot reach past what this period
     * is about and read a record for somebody who is not in it.
     *
     * @param  list<SellerPeriodReport>  $reports
     * @return array<string, array<string, mixed>>
     */
    private function valuesFor(array $reports): array
    {
        if (! $this->records instanceof SuppliesSellerRecords) {
            return [];
        }

        $values = [];

        foreach ($reports as $report) {
            $values[UnclassifiedActivityRule::subjectOf($report->seller)] = $this->records->valuesFor($report->seller);
        }

        return $values;
    }
}
