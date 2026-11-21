<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Tax\DatedTaxRateTable;
use Pushery\Billing\Tax\TaxCalculatorFactory;
use Pushery\Billing\Tax\TaxPoint;
use Pushery\Billing\ValueObjects\Money;

/**
 * Names every invoiced period that a change of rate leaves taxed at another rate than the one of its last day.
 *
 * In a country that applies the rate in force when a supply is carried out, a period carries the rate of its last day
 * ({@see TaxPoint::ratesAtExecution()}). An interval the dated rate history learns after a period was invoiced, or an
 * invoice issued before the package read the rate there, leaves the period taxed at another rate, and the difference
 * is corrected in the return of the day the period was carried out (§ 27 Abs. 1 Satz 3 UStG). This lists each such
 * invoice with its period, its net, the tax it charged, the rate of the period's last day and the tax at that rate. It
 * writes nothing: the correction belongs in the return, and the invoice stays as it was issued.
 *
 * An open or paid invoice with a stated period is read; a void, refunded, uncollectible or draft one is not, and
 * neither is one that charged no tax by a reverse charge or an exemption. A period whose last day the dated history
 * does not cover in its country cannot be judged, and is counted as such rather than as one that carries its rate.
 */
final class TaxRateCorrectionsCommand extends Command
{
    protected $signature = 'billing:tax-rates:corrections
        {--since= : Only periods carried out on or after this day, as YYYY-MM-DD}';

    protected $description = 'Name every invoiced period that a change of rate leaves taxed at another rate than the one of its last day';

    public function handle(TaxCalculatorFactory $tables, TaxPoint $taxPoint, Repository $config): int
    {
        $since = $this->option('since');

        if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $since) !== 1) {
            $this->components->error('--since takes a day as YYYY-MM-DD.');

            return self::INVALID;
        }

        $history = $tables->answeringRateHistory();

        if (! $history instanceof DatedTaxRateTable) {
            $this->components->info('No dated rate history is configured (billing.tax_matrix.history) or bound (ProvidesTaxRateHistory), so no rate has changed under an invoiced period.');

            return self::SUCCESS;
        }

        $home = $config->get('billing.company.country');
        $rows = [];
        $unjudged = 0;

        $query = InvoiceRecord::model()::query()
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Paid->value])
            ->whereNotNull('service_period_end')
            ->whereNotNull('subtotal_minor')
            ->where('reverse_charge', false)
            ->where('tax_exempt', false);

        if (is_string($since)) {
            $query->where('service_period_end', '>=', $since);
        }

        $query->chunkById(200, function (Collection $invoices) use ($history, $taxPoint, $home, &$rows, &$unjudged): void {
            /** @var Collection<int, InvoiceRecord> $invoices */
            foreach ($invoices as $invoice) {
                $row = $this->correctionOf($invoice, $history, $taxPoint, $invoice->oss && is_string($invoice->destination_country) ? $invoice->destination_country : $home);

                if ($row === false) {
                    $unjudged++;
                } elseif ($row !== null) {
                    $rows[] = $row;
                }
            }
        });

        if ($unjudged > 0) {
            $this->components->warn("{$unjudged} invoiced period(s) end on a day the dated rate history does not cover in their country, so their rate was not checked.");
        }

        if ($rows === []) {
            $this->components->info($unjudged > 0 ? 'Every other invoiced period carries the rate of its last day.' : 'Every invoiced period carries the rate of its last day.');

            return self::SUCCESS;
        }

        $this->table(['Invoice', 'Period', 'Country', 'Net', 'Tax charged', 'Rate of the last day', 'Tax at that rate'], $rows);
        $this->components->warn(count($rows).' invoiced period(s) carry another rate than the one of their last day. Correct the difference in the return of that day.');

        return self::SUCCESS;
    }

    /**
     * The row of an invoice whose period was taxed at another rate than the one of its last day, false where the
     * dated history does not cover that day in the invoice's country, and null where there is nothing to correct.
     *
     * The rate it was taxed at is the statutory one where the invoice records it, and otherwise the rate the two
     * amounts give.
     *
     * @param  mixed  $country  the invoice's country as its stored tax basis holds it, which may be any value of decoded JSON
     * @return list<string>|false|null
     */
    private function correctionOf(InvoiceRecord $invoice, DatedTaxRateTable $history, TaxPoint $taxPoint, mixed $country): array|false|null
    {
        $end = $invoice->service_period_end;
        $charged = $invoice->supply_rate_bps ?? $invoice->tax_rate_bps;

        if (! is_string($country) || $end === null || $charged === null || ! $taxPoint->ratesAtExecution($country)) {
            return null;
        }

        $lastDay = CarbonImmutable::parse($end->toDateString());

        if (! $history->covers($country, $lastDay)) {
            return false;
        }

        $rate = $history->rateAt($country, $invoice->tax_rate_category ?? TaxRateCategory::Standard, $lastDay);

        if ($rate === $charged) {
            return null;
        }

        $net = (int) $invoice->subtotal_minor;

        return [
            (string) ($invoice->number ?? '#'.$invoice->id),
            ($invoice->service_period_start?->toDateString() ?? '?').' to '.$lastDay->toDateString(),
            strtoupper($country),
            Money::of($net, $invoice->currency)->toDecimal().' '.$invoice->currency,
            Money::of((int) $invoice->tax_minor, $invoice->currency)->toDecimal().' '.$invoice->currency.' at '.number_format($charged / 100, 2).' %',
            number_format($rate / 100, 2).' %',
            Money::of((int) round($net * $rate / 10_000), $invoice->currency)->toDecimal().' '.$invoice->currency,
        ];
    }
}
