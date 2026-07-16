<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Pushery\Billing\Invoicing\DatevExport;
use Pushery\Billing\Models\InvoiceRecord;
use Throwable;

/**
 * Writes the invoices of a period as a DATEV "Buchungsstapel" (EXTF) file — the booking batch a German
 * tax advisor imports. It is the schedulable, hands-off form of {@see DatevExport}: pick a period, get
 * the file. The period defaults to the previous calendar month, so a monthly cron with no arguments
 * exports "last month" — the natural cadence for handing bookings to the Steuerberater.
 *
 * Only ISSUED invoices are exported (a draft has no `issued_at` and no booking date), and they are
 * ordered by document date so the batch reads chronologically. The account numbers still come from
 * `billing.datev` and must be confirmed with the advisor; left unset the file is structurally valid
 * with blank account fields to fill in — this command does not post anything itself.
 */
final class DatevExportCommand extends Command
{
    protected $signature = 'billing:datev:export
        {--from= : Start of the period (inclusive), e.g. 2026-01-01 — defaults to the first day of last month}
        {--to= : End of the period (inclusive), e.g. 2026-01-31 — defaults to the last day of last month}
        {--path= : Write the EXTF file here instead of to the terminal}';

    protected $description = 'Export a period of invoices as a DATEV EXTF booking batch';

    public function handle(DatevExport $export, Filesystem $files): int
    {
        try {
            $from = $this->bound('from', Carbon::now()->subMonthNoOverflow()->startOfMonth());
            $to = $this->bound('to', Carbon::now()->subMonthNoOverflow()->endOfMonth());
        } catch (Throwable) {
            $this->components->error('Could not read the period; pass --from and --to as dates (YYYY-MM-DD).');

            return self::FAILURE;
        }

        if ($to->lessThan($from)) {
            $this->components->error('The --to date is before --from; there is no period to export.');

            return self::FAILURE;
        }

        $invoices = InvoiceRecord::query()
            ->whereBetween('issued_at', [$from, $to])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $content = $export->export($invoices, $from, $to);
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            $files->put($path, $content);
            $this->components->info("Wrote {$invoices->count()} invoice(s) for {$from->toDateString()}–{$to->toDateString()} to {$path}.");

            return self::SUCCESS;
        }

        $this->output->write($content);
        $this->components->info("Exported {$invoices->count()} invoice(s) for {$from->toDateString()}–{$to->toDateString()}.");

        return self::SUCCESS;
    }

    /** Resolve a period bound from its option, falling back to the given default; start/end-of-day snap the range. */
    private function bound(string $option, Carbon $default): Carbon
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        $parsed = Carbon::parse($value);

        return $option === 'from' ? $parsed->startOfDay() : $parsed->endOfDay();
    }
}
