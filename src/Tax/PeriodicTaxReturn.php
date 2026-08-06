<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\CorrectionOutsideWindow;
use Pushery\Billing\Exceptions\CurrencyMismatch;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\ReportingPeriod;
use Pushery\Billing\ValueObjects\TaxReturnLine;

/**
 * The lines a periodic tax return declares: this period's sales, and corrections to earlier ones.
 *
 * ## Aggregated from the sales, never recomputed from a table
 *
 * Every line's rate is the one the SALE carried, read from its own frozen column. Looking the rate up again
 * at reporting time would use whatever the table says that day, and a country that changed its rate between
 * the sale and the filing would have every one of its sales re-rated — a return that reconciles with itself
 * and with nothing that was ever invoiced. For the same reason the amounts are the sales' own rounded
 * figures summed, not a rate applied to a summed base: a second rounding path would differ by cents that
 * nobody could trace back to a document.
 *
 * ## A correction belongs to the period it is DECLARED in
 *
 * It carries the period it corrects and is never written back into it. That return was filed; a file that
 * changes after filing is not a filing. So a refund of an earlier quarter appears here, in this quarter, as
 * a negative line naming the earlier one.
 *
 * ## The window runs from the DUE DATE
 *
 * A correction is only allowed for so long, and the clock starts when the original return was due — not when
 * its period ended. Those are a month apart, and using the wrong one moves the boundary by exactly that
 * month, in the direction that lets through a correction which is already out of time. Past the window the
 * export REFUSES rather than dropping the line or folding it into the current period: a correction that
 * vanishes is indistinguishable from one that was never owed, and one folded in is a misdeclaration.
 *
 * ## No deductions, ever
 *
 * This return declares tax collected. Input tax has no line here and cannot be given one — it is recovered
 * through a different procedure entirely, and a deduction appearing here would claim a refund twice.
 *
 * Nothing national lives here: the window length is a jurisdiction's number, passed in.
 */
final readonly class PeriodicTaxReturn
{
    /**
     * The lines for one period, aggregated.
     *
     * @param  iterable<InvoiceRecord>  $sales  the documents of the period, including corrections issued in it
     * @param  int  $correctionWindowYears  how long a jurisdiction allows corrections
     * @return list<TaxReturnLine>
     */
    public function linesFor(
        ReportingPeriod $period,
        iterable $sales,
        int $correctionWindowYears,
    ): array {
        /** @var array<string, TaxReturnLine> $lines */
        $lines = [];
        $currency = null;

        foreach ($sales as $sale) {
            // One return covers one currency, and this refuses a batch that mixes them. The shipped caller
            // already scopes its query by currency, so this never fires there — it exists because this method
            // is public and its figures are bare minor units. Hand it 10000 in EUR and 10000 in USD for the
            // same country and rate, and it would return 20000: two different units added as though they were
            // one, on a document that goes to a tax authority.
            //
            // The same reasoning is already applied one line down, where a reissue is skipped HERE rather
            // than only in the caller's query, "so no caller can hand this a list that double-counts". That
            // argument does not stop at reissues. A mixed batch is the other way to hand it a wrong sum.
            $currency ??= $sale->currency;

            if ($sale->currency !== $currency) {
                throw CurrencyMismatch::between($currency, (string) $sale->currency);
            }

            // A restatement is the same sale written down a second time — the full invoice a buyer asked for
            // after their receipt. Declaring it would report tax nobody ever took. Skipped here rather than
            // only in the caller's query, so no caller can hand this a list that double-counts.
            if ($sale->isReissue()) {
                continue;
            }

            $line = $this->lineFor($sale, $period, $correctionWindowYears);

            if (! $line instanceof TaxReturnLine) {
                continue;
            }

            $existing = $lines[$line->key()] ?? null;

            $lines[$line->key()] = $existing instanceof TaxReturnLine
                ? new TaxReturnLine(
                    $line->country,
                    $line->category,
                    $line->rateBps,
                    $existing->netMinor + $line->netMinor,
                    $existing->taxMinor + $line->taxMinor,
                    $line->originPeriod,
                )
                : $line;
        }

        return array_values($lines);
    }

    /**
     * One sale's contribution, or null when it belongs to no line at all.
     *
     * A sale that was not taxed at the buyer's country is not this return's business, and neither is one
     * that names no country — both are declared elsewhere, and guessing a country here would declare a sale
     * into a country it never touched.
     */
    private function lineFor(
        InvoiceRecord $sale,
        ReportingPeriod $period,
        int $correctionWindowYears,
    ): ?TaxReturnLine {
        $country = $sale->destination_country;

        if (! (bool) $sale->oss || ! is_string($country) || $country === '') {
            return null;
        }

        $origin = $this->originPeriodOf($sale, $period, $correctionWindowYears);
        $correcting = $sale->isCorrection();
        $net = $sale->subtotal_minor ?? 0;
        $tax = $sale->tax_minor ?? 0;

        return new TaxReturnLine(
            country: strtoupper($country),
            category: $this->categoryOf($sale),
            rateBps: $this->rateOf($sale),
            // A correction reduces: its document states positive magnitudes and its ROLE inverts them, so
            // the sign is applied here, once, where a return needs it.
            netMinor: $correcting ? -$net : $net,
            taxMinor: $correcting ? -$tax : $tax,
            originPeriod: $origin,
        );
    }

    /**
     * Which period a correcting document corrects, or null when it corrects nothing.
     *
     * A correction issued in the same period as the sale it corrects is not a correction of an earlier
     * return — nothing was filed yet — so it simply nets against this period's own line.
     */
    private function originPeriodOf(
        InvoiceRecord $sale,
        ReportingPeriod $period,
        int $correctionWindowYears,
    ): ?ReportingPeriod {
        if (! $sale->isCorrection()) {
            return null;
        }

        $corrected = $sale->correctedIssuedAt();

        if (! $corrected instanceof CarbonImmutable) {
            return null;
        }

        $origin = ReportingPeriod::containing($corrected);

        if ($origin->equals($period)) {
            return null;
        }

        if (! $origin->correctableOn($period->dueOn(), $correctionWindowYears)) {
            throw CorrectionOutsideWindow::forPeriod($origin->label(), $period->label(), $correctionWindowYears);
        }

        return $origin;
    }

    private function categoryOf(InvoiceRecord $sale): TaxRateCategory
    {
        $category = $sale->tax_rate_category;

        return $category instanceof TaxRateCategory ? $category : TaxRateCategory::Standard;
    }

    /**
     * The rate the sale actually carried.
     *
     * The one-stop-shop column first, because it is what the sale was declared under; the general rate
     * column only where there is none. Never a fresh lookup — a country that moved its rate between the sale
     * and the filing would otherwise have every one of its sales re-rated.
     */
    private function rateOf(InvoiceRecord $sale): int
    {
        $ossRate = $sale->oss_rate;

        if (is_numeric($ossRate)) {
            return (int) round((float) $ossRate * 100);
        }

        return $sale->tax_rate_bps ?? 0;
    }
}
