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
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Contracts\SuppliesMonthlyRecapitulativeStatement;
use Pushery\Billing\Contracts\SuppliesRecapitulativeStatementDeadline;
use Pushery\Billing\Contracts\SuppliesRecapitulativeStatementExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\RecapitulativeStatementIncomplete;
use Pushery\Billing\Exceptions\ReportingRateMissing;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Pushery\Billing\Tax\RecapitulativeStatement;
use Pushery\Billing\Tax\RecapitulativeStatementArchive;
use Pushery\Billing\Tax\ReportingConversion;
use Pushery\Billing\ValueObjects\RecapitulativeStatementPeriod;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * Writes one period's recapitulative statement to a file, for an operator who does not report it from DATEV.
 *
 * Built like `billing:tax-return:export`, for the same reasons: the default is the quarter that has just
 * ended rather than the one still moving, and every run is recorded, a preview included, because a preview
 * piped into a file and reported from there would otherwise leave no trace. A reverse-charged sale that
 * names no VAT id stops the export instead of vanishing from it.
 *
 * A period is a quarter, or a month where the jurisdiction profile makes the statement monthly. A profile
 * that sets a limit on a quarter's supplies of goods has a quarterly export refused once the quarter or one of
 * the four before it went over, because a quarterly file is then the wrong document to file, and a refusal is
 * the only way the operator learns it before filing.
 *
 * The deadline is printed where the jurisdiction profile states one, so the date sits beside the file it
 * belongs to.
 */
final class RecapitulativeStatementExportCommand extends Command
{
    /** How many documents one read of a window holds. */
    private const int PAGE = 500;

    protected $signature = 'billing:recapitulative-statement:export
        {--year= : The year to export (defaults to the quarter that has just ended)}
        {--quarter= : The quarter, 1 to 4}
        {--month= : A month, 1 to 12, where the statement is due for each month}
        {--currency=EUR : Which currency to report}
        {--path= : Write the file here instead of to the terminal}';

    protected $description = 'Export a quarter\'s or a month\'s reverse-charged sales to businesses in other member states, per VAT id';

    public function handle(
        RecapitulativeStatement $statement,
        RecapitulativeStatementArchive $archive,
        CheckpointRegistry $profiles,
        Filesystem $files,
        Repository $config,
    ): int {
        $period = $this->period();

        if (! $period instanceof RecapitulativeStatementPeriod) {
            $this->components->error('Could not read the period; pass --year with --quarter (1 to 4) or with --month (1 to 12).');

            return self::FAILURE;
        }

        $currency = strtoupper((string) $this->option('currency'));
        $profile = $profiles->profile();
        $reporting = $config->get('billing.currency');
        $reporting = is_string($reporting) && $reporting !== '' ? strtoupper($reporting) : 'EUR';

        // In the currency the statement is filed in, every reverse-charged sale belongs in it, at the rate the
        // statement froze for it. Without a rule to convert under, a sale in another currency is refused rather
        // than left out: a statement short by a sale reconciles with nothing that was sold.
        $conversion = $currency === $reporting && $profile instanceof SuppliesRecapitulativeStatementExchangeRateBasis
            ? new ReportingConversion($currency, ExchangeRateLayer::RecapitulativeStatement, ReportingRateMissing::inStatement(...))
            : null;

        if (! $conversion instanceof ReportingConversion && $currency === $reporting) {
            $elsewhere = $this->reverseChargedElsewhere($period->startsOn(), $period->endsOn(), $currency);

            if ($elsewhere > 0) {
                $this->components->error(sprintf(
                    '%d reverse-charged sale(s) of %s are in another currency, and the active jurisdiction profile names no rate to state them in %s at. Leaving them out would file a statement short by those sales.',
                    $elsewhere,
                    $period->label(),
                    $currency,
                ));

                return self::FAILURE;
            }
        }

        try {
            if (! $period->isMonthly() && $profile instanceof SuppliesMonthlyRecapitulativeStatement) {
                $refusal = $this->monthlyInstead($statement, $period->inQuarter(), $currency, $profile->recapitulativeStatementMonthlyThresholdMinor(), $conversion);

                if ($refusal !== null) {
                    $this->components->error($refusal);

                    return self::FAILURE;
                }
            }

            $lines = $statement->linesFor($this->documentsIn($period->startsOn(), $period->endsOn(), $currency, $conversion), $conversion);
        } catch (RecapitulativeStatementIncomplete|ReportingRateMissing $e) {
            // Refused, not dropped: a sale left off the statement looks exactly like one that never happened, and
            // a sale stated without its rate would state an amount nobody converted.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $contents = $archive->store($period, $lines, $currency)->contents;
        $deadline = $this->deadline($profile, $period);

        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->line($contents);
            $this->components->info($deadline);

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);
        $this->components->info(count($lines)." line(s) written to {$path}. {$deadline}");

        return self::SUCCESS;
    }

    /** The period asked for, or the quarter that has just ended. */
    private function period(): ?RecapitulativeStatementPeriod
    {
        $year = $this->option('year');
        $quarter = $this->option('quarter');
        $month = $this->option('month');

        if ($year === null && $quarter === null && $month === null) {
            return RecapitulativeStatementPeriod::quarter(
                ReportingPeriod::containing(CarbonImmutable::instance(Carbon::now())->subMonthsNoOverflow(3)),
            );
        }

        // A quarter and a month together name two periods, and guessing which one was meant files the other.
        if (! is_numeric($year) || ($quarter === null) === ($month === null)) {
            return null;
        }

        try {
            return $month !== null
                ? RecapitulativeStatementPeriod::month((int) $year, is_numeric($month) ? (int) $month : 0)
                : RecapitulativeStatementPeriod::quarter(new ReportingPeriod((int) $year, is_numeric($quarter) ? (int) $quarter : 0));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Why this quarter has to be reported month by month, or null where it may be reported as a quarter.
     *
     * The limit is stated in euros, so it is measured on the euro documents; a statement in another currency
     * is not held to it here.
     */
    private function monthlyInstead(RecapitulativeStatement $statement, ReportingPeriod $quarter, string $currency, int $limitMinor, ?ReportingConversion $conversion = null): ?string
    {
        if ($currency !== 'EUR') {
            return null;
        }

        // The quarter itself and the four before it, the window Article 263 of the Directive names. With a
        // conversion the goods sold in another currency count too, at the statement's rate.
        for ($back = 0; $back <= 4; $back++) {
            $window = ReportingPeriod::containing($quarter->startsOn()->subMonthsNoOverflow(3 * $back));
            $goods = $statement->goodsNetMinor($this->documentsIn($window->startsOn(), $window->endsOn(), 'EUR', $conversion), $conversion);

            if ($goods > $limitMinor) {
                return sprintf(
                    'Supplies of goods came to %s EUR in %s, over the limit of %s EUR, so the statement for %s is due for each month. Export each month with --year and --month.',
                    number_format($goods / 100, 2, '.', ''),
                    $window->label(),
                    number_format($limitMinor / 100, 2, '.', ''),
                    $quarter->label(),
                );
            }
        }

        return null;
    }

    /** Where no profile is active there is no deadline to name, and the sentence says so. */
    private function deadline(?JurisdictionProfile $profile, RecapitulativeStatementPeriod $period): string
    {
        if ($period->month !== null) {
            return $profile instanceof SuppliesMonthlyRecapitulativeStatement
                ? 'Due on '.$profile->recapitulativeStatementMonthDueOn($period->year, $period->month)->toDateString().'.'
                : 'The active jurisdiction profile names no deadline for a month.';
        }

        return $profile instanceof SuppliesRecapitulativeStatementDeadline
            ? 'Due on '.$profile->recapitulativeStatementDueOn($period->inQuarter())->toDateString().'.'
            : 'The active jurisdiction profile names no deadline for it.';
    }

    /**
     * The reverse-charged documents issued in the window, corrections included: in the currency being reported,
     * or in every currency where a conversion states them in it.
     *
     * Only a reverse-charged document can be on the statement, so the others are left in the database rather
     * than loaded to be skipped. The statement still asks each document itself, because its own rule has more
     * to it than the flag. What is read comes a page at a time, with each page's frozen rates: a large platform's
     * quarter held in memory as a whole ran out of it on the day the statement was due, and the threshold check
     * reads five quarters.
     *
     * @return LazyCollection<int, InvoiceRecord>
     */
    private function documentsIn(CarbonImmutable $from, CarbonImmutable $until, string $currency, ?ReportingConversion $conversion = null): LazyCollection
    {
        return InvoiceRecord::model()::query()
            ->when(! $conversion instanceof ReportingConversion, fn (Builder $query): Builder => $query->where('currency', $currency))
            ->with('exchangeRates')
            ->where('reverse_charge', true)
            ->whereBetween('issued_at', [$from, $until])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lazy(self::PAGE);
    }

    /** How many reverse-charged documents of the window are in a currency other than the one being reported. */
    private function reverseChargedElsewhere(CarbonImmutable $from, CarbonImmutable $until, string $currency): int
    {
        return InvoiceRecord::model()::query()
            ->where('reverse_charge', true)
            ->whereBetween('issued_at', [$from, $until])
            ->where('currency', '!=', $currency)
            ->count();
    }
}
