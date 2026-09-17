<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Pushery\Billing\Invoicing\DatevPeriodBatch;
use Pushery\Billing\ValueObjects\AccountReconciliation;
use Pushery\Billing\ValueObjects\Money;
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

    public function handle(DatevPeriodBatch $periods, Filesystem $files): int
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

        // Assembled by DatevPeriodBatch, not here. Both silent omissions this command carried — provider
        // fees and voucher movements missing from every real monthly batch — were the assembly drifting
        // away from what export() accepts, and a second caller re-deriving it would do it a third time.
        $batch = $periods->render($from, $to);
        $content = $batch['content'];

        $path = $this->option('path');

        // EVERY count, always, including the zeroes. A line that reports only some of what was loaded reads
        // as the whole batch, so an operator cannot tell a month whose vouchers were loaded and empty from
        // a month whose vouchers were never loaded at all. For the whole life of this command it was the
        // second, for both fees and vouchers, and that is precisely why the number goes in the line rather
        // than being left to be rediscovered.
        $counted = "{$batch['invoices']} invoice(s), {$batch['providerFees']} provider fee(s), "
            ."{$batch['voucherMovements']} voucher movement(s) and {$batch['creditMovements']} credit "
            ."movement(s) for {$from->toDateString()}–{$to->toDateString()}";

        if (is_string($path) && $path !== '') {
            $files->put($path, $content);
            $this->components->info("Wrote {$counted} to {$path}.");
            $this->reportUnbookedCredit($batch['unbookedCreditMinor'], $batch['currency']);

            return $this->reportTie($batch['reconciliation']);
        }

        $this->output->write($content);
        $this->components->info("Exported {$counted}.");
        $this->reportUnbookedCredit($batch['unbookedCreditMinor'], $batch['currency']);

        return $this->reportTie($batch['reconciliation']);
    }

    /**
     * State whether the merchant payables in the emitted file tie out to the sub-ledger.
     *
     * ## The file is written either way, and the command still fails
     *
     * Withholding the batch would take away the only thing that can be used to find out WHY it does not
     * tie — the difference is a statement about that file, and an operator cannot investigate a file they
     * were not given. But a close that does not tie out is not a close: the two sides are one obligation
     * counted twice, there is no reading under which they legitimately differ, and a command that reports
     * success is what a scheduler and an operator both act on.
     *
     * ## Silent for an installation that has no merchants
     *
     * With no payables the sub-ledger is empty and the batch books nothing to the collective account, so
     * both sides are zero and this is a line nobody sees. The confirmation is printed only where there is
     * something to confirm — a figure of 0.00 on every export is how an operator learns to skip the line
     * that matters.
     */
    private function reportTie(AccountReconciliation $reconciliation): int
    {
        if ($reconciliation->isBalanced()) {
            if (! $reconciliation->subLedgerTotal->isZero()) {
                $this->components->info('Merchant payables tie out at '.$this->amount($reconciliation->subLedgerTotal).'.');
            }

            return self::SUCCESS;
        }

        $this->components->error('The batch does not tie out and must not be filed.');

        // Both figures on their own lines, never only the difference. "Off by 12.50" does not say which side
        // is short, and the two point at opposite defects: a sub-ledger above the batch means the export left
        // a payable out, below it means the batch booked one the books do not carry.
        //
        // Written with line(), not through a console component: a component wraps a long message at the
        // terminal width, and a figure an operator is meant to read — or a test is meant to assert — must not
        // depend on where that wrap happens to fall.
        $this->line('  Sub-ledger holds '.$this->amount($reconciliation->subLedgerTotal).' in merchant payables.');
        $this->line('  Exported batch books '.$this->amount($reconciliation->collectiveAccountBalance).' to the payables accounts.');
        $this->line('  Difference: '.$this->amount($reconciliation->difference()).'.');
        $this->line('  The file was written so the difference can be traced. Do not file it until it reconciles.');

        return self::FAILURE;
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

    /** An amount as an operator reads it off a statement: the decimal figure and the currency it is in. */
    private function amount(Money $money): string
    {
        return $money->toDecimal().' '.$money->currency;
    }

    /**
     * State the credit movement this batch could not book, always, including the zero.
     *
     * ## The figure existed and stood in front of nobody
     *
     * `DatevPeriodBatch` has computed it since credit movements reached the export, and argued for it in
     * its own comment: *"Reporting the amount keeps the batch produceable and puts the gap in front of the
     * person who runs it — a zero here and a zero because nothing happened look identical, and only this
     * figure tells them apart."* Measured across the package, the key was read in exactly one place, a test
     * assertion. Neither this command nor the admin console touched it, so the person who runs the batch
     * never saw the number the comment promised them — the same shape as a guard with no lane: computed,
     * correct, and without effect, while the prose beside it reads as an assurance somebody was told.
     *
     * ## Why the zero is printed too
     *
     * It is the whole argument of the figure. A period that booked everything and a period whose credit
     * movements were never loaded both produce silence, and the operator cannot tell them apart from the
     * absence of a line. This is the same rule the counts above follow, for the same reason.
     *
     * ## Why it is not a failure
     *
     * A grant with no account pair is an open question about the chart of accounts, not a defect in the
     * batch: the file is correct and complete about everything it does book. The exit code stays with the
     * reconciliation, which is an arithmetic contradiction and a different kind of statement.
     *
     * The figure is a NET, and it can be negative. Spending such a grant takes its share back out, so a
     * period that granted and redeemed the same credit owes the books nothing and says so with a zero. Read
     * as a running total of grants it would grow forever while describing a gap that had already closed.
     */
    private function reportUnbookedCredit(int $minorUnits, string $currency): void
    {
        $amount = $this->amount(Money::of($minorUnits, $currency));

        if ($minorUnits === 0) {
            $this->components->info('No credit movement was left unbooked.');

            return;
        }

        // Two short lines rather than one long one, and that is not formatting taste. `components->warn()`
        // wraps at the terminal width, so a phrase near column 80 is split across lines — and anything
        // reading the output for it, a test or a person grepping a CI log, then does not find it. Measured:
        // the single-sentence version broke between "no account" and "pair".
        $this->components->warn("Unbooked credit movement: {$amount}.");
        $this->components->warn('It has no account pair. The file is complete about everything else.');
    }
}
