<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Exceptions\ReportingPeriodNotClosed;
use Pushery\Billing\Tax\FreezeRecapitulativeStatementRates;
use Pushery\Billing\ValueObjects\RecapitulativeStatementPeriod;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * Give a closed period's reverse-charged sales in another currency the rate their recapitulative statement states.
 *
 * Its own moment for the reason the reporting freeze has one ({@see FreezeRecapitulativeStatementRates}), and its
 * own command because the statement's period is not always a quarter: a seller over the monthly limit files it
 * for each month, by the 25th day after the month, which is long before the quarter's freeze would run. So it
 * names a period the way `billing:recapitulative-statement:export` does, a quarter or a month, and never two
 * free dates.
 */
final class FreezeRecapitulativeStatementRatesCommand extends Command
{
    protected $signature = 'billing:exchange-rates:freeze-statement
        {--year= : The year (defaults to the quarter that has just ended)}
        {--quarter= : The quarter, 1 to 4}
        {--month= : A month, 1 to 12, where the statement is due for each month}';

    protected $description = 'Freeze the recapitulative statement\'s exchange rate onto a closed period\'s reverse-charged sales';

    public function handle(FreezeRecapitulativeStatementRates $rates): int
    {
        $period = $this->period();

        if (! $period instanceof RecapitulativeStatementPeriod) {
            $this->components->error('Could not read the period; pass --year with --quarter (1 to 4) or with --month (1 to 12).');

            return self::FAILURE;
        }

        try {
            $frozen = $rates->forPeriod($period->startsOn(), $period->endsOn(), CarbonImmutable::now());
        } catch (ReportingPeriodNotClosed $notClosed) {
            $this->components->error($notClosed->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Froze the recapitulative statement rate on %d document(s) for %s.', $frozen, $period->label()));

        return self::SUCCESS;
    }

    /** The period asked for, or the quarter that has just ended — read the way the statement's export reads it. */
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

        // A quarter and a month together name two periods, and guessing which one was meant freezes the other.
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
}
