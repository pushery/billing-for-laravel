<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Pushery\Billing\Enums\TaxReturnScheme;
use Pushery\Billing\Exceptions\CorrectionOutsideWindow;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Tax\PeriodicTaxReturn;
use Pushery\Billing\Tax\TaxReturnExportArchive;
use Pushery\Billing\ValueObjects\ImportSchemePeriod;

/**
 * Writes one month of the import scheme's return to a file.
 *
 * The import scheme declares the distance sales of goods imported in consignments of up to 150 euros, which the
 * platform supplies itself where it facilitates them, month by month (Art. 369af VAT Directive). The return takes
 * the documents that carry the import scheme's flag and were issued in the month, corrections of earlier months
 * included, and the command defaults to the month that has just ended rather than the one still running.
 *
 * The return is made out in the reporting currency. A document of the month issued in another one stops the export
 * rather than being converted: it converts at the central bank's rate of the month's last day, and this package
 * freezes reporting rates for a quarter, not for a month, so a converted figure would be one no record supports.
 *
 * Every run is recorded under the import scheme, including one that only prints, as the one-stop-shop export
 * records its own.
 */
final class ImportSchemeReturnExportCommand extends Command
{
    /** How many documents one read of the month holds. */
    private const int PAGE = 500;

    protected $signature = 'billing:import-scheme-return:export
        {--year= : The year to export (defaults to the month that has just ended)}
        {--month= : The month, 1 to 12}
        {--path= : Write the file here instead of to the terminal}';

    protected $description = 'Export a month of import scheme sales as return lines, corrections included';

    public function handle(
        PeriodicTaxReturn $return,
        TaxReturnExportArchive $archive,
        Repository $config,
        Filesystem $files,
    ): int {
        $period = $this->period();

        if (! $period instanceof ImportSchemePeriod) {
            $this->components->error('Could not read the month; pass --year and --month (1 to 12).');

            return self::FAILURE;
        }

        $reporting = $config->get('billing.currency');
        $currency = is_string($reporting) && $reporting !== '' ? strtoupper($reporting) : 'EUR';
        $foreign = $this->currenciesOtherThan($period, $currency);

        if ($foreign !== '') {
            $this->components->error(
                "The import scheme's return for {$period->label()} is made out in {$currency}, and sales of the month "
                ."were issued in {$foreign}. Such a sale converts at the central bank's rate of the month's last day, "
                .'which this package does not freeze for a month, so nothing was exported.'
            );

            return self::FAILURE;
        }

        $window = $config->get('billing.tax_oss.correction_window_years');

        try {
            $lines = $return->linesFor($period, $this->salesIn($period), is_int($window) ? $window : 3, scheme: TaxReturnScheme::ImportScheme);
        } catch (CorrectionOutsideWindow $e) {
            // Refused, not dropped: a correction that vanishes looks exactly like one that was never owed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $record = $archive->store($period, $lines, $currency, scheme: TaxReturnScheme::ImportScheme);
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->line($record->contents);

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $record->contents);

        $this->components->info(count($lines)." line(s) written to {$path}.");

        return self::SUCCESS;
    }

    /** The month asked for, or the one that has just ended. */
    private function period(): ?ImportSchemePeriod
    {
        $year = $this->option('year');
        $month = $this->option('month');

        if ($year === null && $month === null) {
            return ImportSchemePeriod::containing(CarbonImmutable::instance(Carbon::now())->subMonthNoOverflow());
        }

        if (! is_numeric($year) || ! is_numeric($month)) {
            return null;
        }

        try {
            return new ImportSchemePeriod((int) $year, (int) $month);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The documents of the month that the import scheme declares, a page at a time and in the order of the lines.
     *
     * @return LazyCollection<int, InvoiceRecord>
     */
    private function salesIn(ImportSchemePeriod $period): LazyCollection
    {
        return InvoiceRecord::model()::query()
            ->where('ioss', true)
            ->whereBetween('issued_at', [$period->startsOn(), $period->endsOn()])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lazy(self::PAGE);
    }

    /** The other currencies the month's import scheme documents were issued in, or an empty string where none were. */
    private function currenciesOtherThan(ImportSchemePeriod $period, string $currency): string
    {
        return InvoiceRecord::model()::query()
            ->where('ioss', true)
            ->whereBetween('issued_at', [$period->startsOn(), $period->endsOn()])
            ->where('currency', '!=', $currency)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->implode(', ');
    }
}
