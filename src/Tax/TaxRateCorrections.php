<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Every invoiced period that a change of rate leaves taxed at another rate than the one of its last day, as values an
 * application can read. `billing:tax-rates:corrections` prints what this names.
 *
 * In a country that applies the rate in force when a supply is carried out, a period carries the rate of its last day
 * ({@see TaxPoint::ratesAtExecution()}). An interval the dated rate history learns after a period was invoiced, or an
 * invoice issued before the package read the rate there, leaves the period taxed at another rate, and the difference
 * is corrected in the return of the day the period was carried out (§ 27 Abs. 1 Satz 3 UStG). Nothing is written: the
 * correction belongs in the return, and the invoice stays as it was issued.
 *
 * An open or paid invoice with a stated period is read; a void, refunded, uncollectible or draft one is not, and
 * neither is one that charged no tax by a reverse charge or an exemption. A period whose last day the dated history
 * does not cover in its country cannot be judged, and is counted as such rather than as one that carries its rate.
 */
final readonly class TaxRateCorrections
{
    public function __construct(
        private TaxCalculatorFactory $tables,
        private TaxPoint $taxPoint,
        private Repository $config,
    ) {}

    /** Whether a dated rate history is configured or bound. Without one no rate has changed under an invoiced period. */
    public function haveAHistory(): bool
    {
        return $this->tables->answeringRateHistory() instanceof DatedTaxRateTable;
    }

    /**
     * The corrections, in the order of the invoices, and how many invoiced periods end on a day the dated history does
     * not cover in their country, which are not judged.
     *
     * @param  CarbonImmutable|null  $since  only periods carried out on or after this day
     * @return array{corrections: list<TaxRateCorrection>, unjudged: int}
     */
    public function find(?CarbonImmutable $since = null): array
    {
        $history = $this->tables->answeringRateHistory();

        if (! $history instanceof DatedTaxRateTable) {
            return ['corrections' => [], 'unjudged' => 0];
        }

        $home = $this->config->get('billing.company.country');
        $corrections = [];
        $unjudged = 0;

        $query = InvoiceRecord::model()::query()
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Paid->value])
            ->whereNotNull('service_period_end')
            ->whereNotNull('subtotal_minor')
            ->where('reverse_charge', false)
            ->where('tax_exempt', false);

        if ($since instanceof CarbonImmutable) {
            $query->where('service_period_end', '>=', $since->toDateString());
        }

        $query->chunkById(200, function (Collection $invoices) use ($history, $home, &$corrections, &$unjudged): void {
            /** @var Collection<int, InvoiceRecord> $invoices */
            foreach ($invoices as $invoice) {
                $correction = $this->correctionOf($invoice, $history, $invoice->oss && is_string($invoice->destination_country) ? $invoice->destination_country : $home);

                if ($correction === false) {
                    $unjudged++;
                } elseif ($correction instanceof TaxRateCorrection) {
                    $corrections[] = $correction;
                }
            }
        });

        return ['corrections' => $corrections, 'unjudged' => $unjudged];
    }

    /**
     * The correction of an invoice whose period was taxed at another rate than the one of its last day, false where the
     * dated history does not cover that day in the invoice's country, and null where there is nothing to correct.
     *
     * The rate it was taxed at is the statutory one where the invoice records it, and otherwise the rate the two
     * amounts give.
     *
     * @param  mixed  $country  the invoice's country as its stored tax basis holds it, which may be any value of decoded JSON
     */
    private function correctionOf(InvoiceRecord $invoice, DatedTaxRateTable $history, mixed $country): TaxRateCorrection|false|null
    {
        $end = $invoice->service_period_end;
        $charged = $invoice->supply_rate_bps ?? $invoice->tax_rate_bps;

        if (! is_string($country) || $end === null || $charged === null || ! $this->taxPoint->ratesAtExecution($country)) {
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
        $start = $invoice->service_period_start;

        return new TaxRateCorrection(
            invoiceId: $invoice->id,
            number: $invoice->number,
            ownerType: $invoice->owner_type,
            ownerId: $invoice->owner_id,
            periodStart: $start === null ? null : CarbonImmutable::parse($start->toDateString()),
            periodEnd: $lastDay,
            country: strtoupper($country),
            currency: $invoice->currency,
            netMinor: $net,
            taxChargedMinor: (int) $invoice->tax_minor,
            chargedRateBps: $charged,
            rateOfTheLastDayBps: $rate,
            taxAtThatRateMinor: (int) round($net * $rate / 10_000),
        );
    }
}
