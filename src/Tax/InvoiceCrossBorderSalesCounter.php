<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\CrossBorderSalesCounter;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * The shipped counter: a projection over the invoices, never a stored total.
 *
 * ## What counts
 *
 * A sale is cross-border here when the document names a destination country that is not the seller's own.
 * That is a property of the document rather than of a setting, which is what lets the figure be rebuilt from
 * the invoices at any time and come out the same. A stored running total would drift the first time an
 * invoice was corrected, and drift in exactly the direction nobody checks.
 *
 * Business sales are out. The threshold is about supplies to consumers; counting a reverse-charge sale into
 * it would push a seller over a line that sale has nothing to do with, and the tax on it was never the
 * seller's to begin with.
 *
 * ## Why the crossing sale has to be identifiable
 *
 * The sale that takes the running total past the limit is itself taxed at the destination. So "the limit was
 * passed this year" is not a usable answer — the caller has to know which invoices fall on which side of it,
 * and that means walking the year in the order the sales were issued rather than summing it.
 */
final readonly class InvoiceCrossBorderSalesCounter implements CrossBorderSalesCounter
{
    public function crossBorderNetIn(int $year, string $currency): Money
    {
        $total = 0;

        foreach ($this->salesIn($year, $currency) as $sale) {
            $total += $sale->subtotal_minor ?? 0;
        }

        return Money::of($total, strtoupper($currency));
    }

    /**
     * @return ?array{reference: string, cumulativeMinor: int}
     */
    public function firstSaleAbove(int $year, string $currency, int $limitMinor): ?array
    {
        $running = 0;

        foreach ($this->salesIn($year, $currency) as $sale) {
            $running += $sale->subtotal_minor ?? 0;

            if ($running > $limitMinor) {
                return [
                    'reference' => $sale->number ?? (string) $sale->id,
                    'cumulativeMinor' => $running,
                ];
            }
        }

        return null;
    }

    /**
     * The year's cross-border consumer sales, oldest first.
     *
     * Ordered by the day they were issued and then by id, because two sales on one day still happened in an
     * order — and without a tiebreak the sale reported as the crossing one would depend on how the database
     * felt like returning them.
     *
     * @return list<InvoiceRecord>
     */
    private function salesIn(int $year, string $currency): array
    {
        /** @var list<InvoiceRecord> $rows */
        $rows = InvoiceRecord::query()
            ->where('currency', strtoupper($currency))
            ->whereNotNull('destination_country')
            ->where('destination_country', '!=', '')
            // A reverse-charge sale is a business one; the threshold is about supplies to consumers.
            ->where('reverse_charge', false)
            // A correction is already a negative in its own right through its role; counting it as a sale
            // would add the amount it takes away.
            ->whereNull('credited_invoice_id')
            ->whereNull('credited_invoice_number')
            // A full invoice a buyer asked for after their receipt states a sale already counted here.
            // Counting it again would push a seller over a limit on turnover they never made twice.
            ->whereNull('reissue_of_invoice_id')
            ->whereBetween('issued_at', [
                sprintf('%04d-01-01 00:00:00', $year),
                sprintf('%04d-12-31 23:59:59', $year),
            ])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }
}
