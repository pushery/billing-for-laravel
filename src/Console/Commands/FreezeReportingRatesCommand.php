<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Exceptions\ReportingPeriodNotClosed;
use Pushery\Billing\Tax\FreezeReportingRates;

/**
 * Give a closed period's documents the rate their return will be filed on.
 *
 * ## Why this is a command and not part of issuing a document
 *
 * The rule converts at the last day of the period. On the day a sale is booked that day has not happened,
 * so there is nothing to freeze — see {@see FreezeReportingRates}. The work therefore has its own moment,
 * after the period closes and before the return is filed, and a command is what makes that moment
 * schedulable by whoever owns the filing calendar.
 *
 * ## It names a quarter rather than two dates
 *
 * The one-stop-shop period IS a calendar quarter, so asking for `--year` and `--quarter` says what is meant
 * and makes a half-open range impossible to express. Two free dates would let somebody file a period that
 * does not exist, and the resulting figures would look entirely normal.
 */
final class FreezeReportingRatesCommand extends Command
{
    protected $signature = 'billing:exchange-rates:freeze-reporting {--year= : calendar year} {--quarter= : 1-4}';

    protected $description = 'Freeze the reporting-layer exchange rate onto a closed period\'s documents';

    public function handle(FreezeReportingRates $rates): int
    {
        $now = CarbonImmutable::now();

        $year = $this->option('year');
        $year = is_numeric($year) ? (int) $year : $now->year;

        $quarter = $this->option('quarter');
        $quarter = is_numeric($quarter) ? (int) $quarter : null;

        if ($quarter === null) {
            // The quarter BEFORE the current one, because the current one cannot be closed. Defaulting to
            // "now" would make the bare command always throw, which teaches nothing and looks like a bug.
            $previous = $now->subQuarterNoOverflow();
            $year = $previous->year;
            $quarter = $previous->quarter;
        }

        if ($quarter < 1 || $quarter > 4) {
            $this->components->error("There is no quarter {$quarter}. Pass --quarter=1 through 4.");

            return self::FAILURE;
        }

        // Parsed rather than `create()`, which is typed nullable and would need a guard that can never
        // fire here to satisfy the analyzer. A formatted date string cannot be ambiguous.
        $start = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1))->startOfDay();
        $end = $start->addMonthsNoOverflow(3)->subDay();

        try {
            $frozen = $rates->forPeriod($start, $end, $now);
        } catch (ReportingPeriodNotClosed $notClosed) {
            $this->components->error($notClosed->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Froze the reporting rate on %d document(s) for %d-Q%d, converted at %s.',
            $frozen,
            $year,
            $quarter,
            $end->toDateString(),
        ));

        return self::SUCCESS;
    }
}
