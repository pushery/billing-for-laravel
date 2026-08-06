<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Pushery\Billing\Invoicing\DatevExport;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\ProviderFee;
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
            // A restatement — the full invoice a buyer asked for after their receipt — is the same sale
            // stated again. Booking it would double the revenue and the tax in the books.
            ->whereNull('reissue_of_invoice_id')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        // What the provider charged in the same period, and it was missing entirely. `DatevExport::export()`
        // has taken these as its fifth parameter for as long as the PSP-fee accounts have existed, and this
        // command — the only production caller of export() in the package — passed three arguments. So the
        // accounts were configured, the booking was written, and every real monthly batch contained zero
        // provider fees. Nothing was red, because the test that proves the booking passes the fees itself.
        //
        // A dispute fee is what makes the omission expensive rather than untidy: the provider is established
        // abroad, so the fee is an inbound supply carrying reverse-charge VAT the platform self-assesses AND
        // deducts. A month that books none declares neither side of it.
        $providerFees = ProviderFee::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // `voucherMovements`, export()'s sixth parameter, is still not passed — and that is stated here rather
        // than left to be rediscovered, because it is the same shape as the defect above. It is not the same
        // fix: a provider fee is a persisted row waiting to be loaded, while a voucher movement is a value
        // object `VoucherLedger::redeem()`/`expire()` return and nothing stores. There is no movements table
        // and no production caller of either method, so there is nothing here to load yet. Passing an empty
        // list would look like coverage of a capability that has no producer.
        $content = $export->export($invoices, $from, $to, providerFees: $providerFees);
        $path = $this->option('path');

        // Both counts, always — including the zeroes. A line that reports only invoices reads as the whole
        // batch, so an operator could not tell a month whose fees were loaded and empty from a month whose
        // fees were never loaded at all. For the whole life of this command it was the second.
        $counted = "{$invoices->count()} invoice(s) and {$providerFees->count()} provider fee(s) for "
            ."{$from->toDateString()}–{$to->toDateString()}";

        if (is_string($path) && $path !== '') {
            $files->put($path, $content);
            $this->components->info("Wrote {$counted} to {$path}.");

            return self::SUCCESS;
        }

        $this->output->write($content);
        $this->components->info("Exported {$counted}.");

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
