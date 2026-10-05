<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\CorrectionOutsideWindow;
use Pushery\Billing\Exceptions\ReportingRateMissing;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Tax\PeriodicTaxReturn;
use Pushery\Billing\Tax\ReportingConversion;
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
    /** How many documents one read of the period holds. */
    private const int PAGE = 500;

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
        $reporting = $config->get('billing.currency');
        $reporting = is_string($reporting) && $reporting !== '' ? strtoupper($reporting) : 'EUR';

        // In the currency the return is filed in, every sale of the period belongs in it, whatever currency it was
        // issued in, at the reporting rate frozen onto it. Scoped to that currency alone, a sale in SEK was left out
        // of the EUR return and stood in a SEK file no one-stop-shop portal takes.
        $conversion = $currency === $reporting ? new ReportingConversion($reporting, ExchangeRateLayer::Reporting, ReportingRateMissing::on(...)) : null;

        try {
            $lines = $return->linesFor(
                $period,
                $this->salesIn($period, $conversion instanceof ReportingConversion ? null : $currency),
                is_int($window) ? $window : 3,
                $conversion,
            );
        } catch (CorrectionOutsideWindow|ReportingRateMissing $e) {
            // Refused, not dropped: a correction that vanishes looks exactly like one that was never owed, and a
            // sale left out for want of a rate looks exactly like one that was never made.
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
     * The documents of the period — issued in it, in the currency being reported, or in any with none named.
     *
     * Read a page at a time rather than all at once. A document carries its frozen lines and buyer, and a large
     * platform's quarter held in memory as a whole ran out of it on the day the return was due. The pages keep
     * the order the lines are written in.
     *
     * @return LazyCollection<int, InvoiceRecord>
     */
    private function salesIn(ReportingPeriod $period, ?string $currency): LazyCollection
    {
        return InvoiceRecord::model()::query()
            ->when($currency !== null, fn (Builder $query): Builder => $query->where('currency', $currency))
            ->with('exchangeRates')
            ->whereBetween('issued_at', [$period->startsOn(), $period->endsOn()])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lazy(self::PAGE);
    }
}
