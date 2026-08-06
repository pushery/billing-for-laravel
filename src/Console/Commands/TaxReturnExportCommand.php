<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Exceptions\CorrectionOutsideWindow;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Tax\PeriodicTaxReturn;
use Pushery\Billing\Tax\TaxReturnExportArchive;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * Writes one period's return lines to a file.
 *
 * Defaults to the period that has just ended rather than the one running, because a return is filed for a
 * period that is over — exporting the current quarter would produce a figure that is still moving, and a
 * figure still moving is the one somebody files by mistake.
 *
 * A correction the jurisdiction no longer allows stops the export instead of quietly dropping the line: a
 * correction that vanishes is indistinguishable from one that was never owed, and the person filing would
 * have no way to know a figure was left out.
 *
 * EVERY run is recorded, including one that only prints. The record says when a file was produced, not that
 * anybody filed it — and a preview piped into a file and filed from there is exactly the case that would
 * otherwise leave no trace at all. Rows are cheap; a quarter whose figures nobody can reconstruct is not.
 */
final class TaxReturnExportCommand extends Command
{
    protected $signature = 'billing:tax-return:export
        {--year= : The year to export (defaults to the quarter that has just ended)}
        {--quarter= : The quarter, 1 to 4}
        {--currency=EUR : Which currency to report}
        {--path= : Write the file here instead of to the terminal}';

    protected $description = 'Export a period of sales as tax-return lines, corrections included';

    public function handle(
        PeriodicTaxReturn $return,
        TaxReturnExportArchive $archive,
        Repository $config,
        Filesystem $files,
    ): int {
        $period = $this->period();

        if (! $period instanceof ReportingPeriod) {
            $this->components->error('Could not read the period; pass --year and --quarter (1 to 4).');

            return self::FAILURE;
        }

        $currency = strtoupper((string) $this->option('currency'));
        $window = $config->get('billing.tax_oss.correction_window_years');

        try {
            $lines = $return->linesFor($period, $this->salesIn($period, $currency), is_int($window) ? $window : 3);
        } catch (CorrectionOutsideWindow $e) {
            // Refused, not dropped: a correction that vanishes looks exactly like one that was never owed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $record = $archive->store($period, $lines, $currency);
        $contents = $record->contents;
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->line($contents);

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->info(count($lines)." line(s) written to {$path}.");

        return self::SUCCESS;
    }

    /** The period asked for, or the one that has just ended. */
    private function period(): ?ReportingPeriod
    {
        $year = $this->option('year');
        $quarter = $this->option('quarter');

        if ($year === null && $quarter === null) {
            return ReportingPeriod::containing(CarbonImmutable::instance(Carbon::now())->subMonthsNoOverflow(3));
        }

        if (! is_numeric($year) || ! is_numeric($quarter)) {
            return null;
        }

        try {
            return new ReportingPeriod((int) $year, (int) $quarter);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The documents of the period — issued in it, in the currency being reported.
     *
     * @return list<InvoiceRecord>
     */
    private function salesIn(ReportingPeriod $period, string $currency): array
    {
        /** @var list<InvoiceRecord> $rows */
        $rows = InvoiceRecord::query()
            ->where('currency', $currency)
            ->whereBetween('issued_at', [$period->startsOn(), $period->endsOn()])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }
}
