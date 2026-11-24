<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pushery\Billing\Tax\TaxRateCorrection;
use Pushery\Billing\Tax\TaxRateCorrections;
use Pushery\Billing\ValueObjects\Money;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Names every invoiced period that a change of rate leaves taxed at another rate than the one of its last day.
 *
 * It prints what {@see TaxRateCorrections} names, which an application reads as values: each invoice with its period,
 * its net, the tax it charged, the rate of the period's last day and the tax at that rate. It writes nothing: the
 * correction belongs in the return of the day the period was carried out, and the invoice stays as it was issued.
 */
final class TaxRateCorrectionsCommand extends Command
{
    protected $signature = 'billing:tax-rates:corrections
        {--since= : Only periods carried out on or after this day, as YYYY-MM-DD}';

    protected $description = 'Name every invoiced period that a change of rate leaves taxed at another rate than the one of its last day';

    public function handle(TaxRateCorrections $corrections): int
    {
        $since = $this->option('since');

        if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $since) !== 1) {
            $this->components->error('--since takes a day as YYYY-MM-DD.');

            return self::INVALID;
        }

        if (! $corrections->haveAHistory()) {
            $this->components->info('No dated rate history is configured (billing.tax_matrix.history) or bound (ProvidesTaxRateHistory), so no rate has changed under an invoiced period.');

            return self::SUCCESS;
        }

        ['corrections' => $found, 'unjudged' => $unjudged] = $corrections->find(is_string($since) ? CarbonImmutable::parse($since) : null);
        $rows = array_map($this->rowOf(...), $found);

        if ($unjudged > 0) {
            $this->components->warn(OutputFormatter::escape("{$unjudged} invoiced period(s) end on a day the dated rate history does not cover in their country, so their rate was not checked."));
        }

        if ($rows === []) {
            $this->components->info(OutputFormatter::escape($unjudged > 0 ? 'Every other invoiced period carries the rate of its last day.' : 'Every invoiced period carries the rate of its last day.'));

            return self::SUCCESS;
        }

        $this->table(['Invoice', 'Period', 'Country', 'Net', 'Tax charged', 'Rate of the last day', 'Tax at that rate'], $rows);
        $this->components->warn(count($rows).' invoiced period(s) carry another rate than the one of their last day. Correct the difference in the return of that day.');

        return self::SUCCESS;
    }

    /**
     * The row of a correction, its invoice number escaped.
     *
     * @return list<string>
     */
    private function rowOf(TaxRateCorrection $correction): array
    {
        return [
            OutputFormatter::escape($correction->number ?? '#'.$correction->invoiceId),
            ($correction->periodStart?->toDateString() ?? '?').' to '.$correction->periodEnd->toDateString(),
            $correction->country,
            Money::of($correction->netMinor, $correction->currency)->toDecimal().' '.$correction->currency,
            Money::of($correction->taxChargedMinor, $correction->currency)->toDecimal().' '.$correction->currency.' at '.number_format($correction->chargedRateBps / 100, 2).' %',
            number_format($correction->rateOfTheLastDayBps / 100, 2).' %',
            Money::of($correction->taxAtThatRateMinor, $correction->currency)->toDecimal().' '.$correction->currency,
        ];
    }
}
