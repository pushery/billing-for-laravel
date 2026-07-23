<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Concerns;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Invoicing\Line;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * The syntax-agnostic invoice model shared by every EN 16931 writer: the seller and buyer parties, the
 * line items, and the per-rate tax breakdown. UBL (XRechnung) and CII (ZUGFeRD) are two syntaxes over
 * the SAME semantic model, so this normalization lives once — a tax band computed one way for XRechnung
 * and another for ZUGFeRD would be a silent conformance drift between the two outputs of one invoice.
 *
 * The consuming class must expose a `Repository $config` property (the seller is the platform,
 * config('billing.company')).
 */
trait NormalizesInvoiceModel
{
    abstract private function config(): Repository;

    private function seller(): Party
    {
        return Party::fromArray($this->companyArray('billing.company'));
    }

    private function buyer(InvoiceRecord $invoice): Party
    {
        $buyer = $invoice->getAttribute('buyer');

        return Party::fromArray(is_array($buyer) ? $buyer : []);
    }

    /** @return array<array-key, mixed> */
    private function companyArray(string $key): array
    {
        $value = $this->config()->get($key);

        return is_array($value) ? $value : [];
    }

    /**
     * The buyer's BT-10 reference (the Leitweg-ID for a German B2G supply), from the invoice's own
     * `buyer_reference` column. The column is authoritative — it is the frozen value the invoice was issued
     * with — and the buyer snapshot's `reference` is only a fallback for a row written before the column
     * existed. Null when neither carries one.
     */
    private function buyerReference(InvoiceRecord $invoice): ?string
    {
        $column = $invoice->buyer_reference;

        if (is_string($column) && $column !== '') {
            return $column;
        }

        $buyer = $invoice->getAttribute('buyer');
        $reference = is_array($buyer) ? ($buyer['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * The BT-120 tax exemption / reverse-charge reason text, from the invoice's `vat_note` column.
     *
     * It is DERIVED, not a literal: a reverse charge without an explicit note falls back to the standard
     * "Reverse charge" wording, but a stored note (an OSS reference, a specific exemption clause) is used as
     * written. Null when the supply carries no exemption reason at all.
     */
    private function vatNote(InvoiceRecord $invoice, bool $reverseCharge): ?string
    {
        $note = $invoice->vat_note;

        if (is_string($note) && $note !== '') {
            return $note;
        }

        return $reverseCharge ? 'Reverse charge' : null;
    }

    /**
     * @return list<Line>
     */
    private function lines(InvoiceRecord $invoice): array
    {
        $lines = $invoice->getAttribute('lines');
        $out = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line)) {
                $out[] = Line::fromArray($line);
            }
        }

        return $out;
    }

    /**
     * The per-rate tax bands to render, reverse-charge aware. A normal supply groups line net by rate; a
     * reverse charge is a SINGLE AE @ 0% band over the whole taxable base — lines at distinct NOTIONAL rates
     * must not emit multiple zero-rated category groups, which is a non-conformant EN 16931 breakdown.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function taxBandsFor(array $lines, bool $reverseCharge): array
    {
        if (! $reverseCharge) {
            return $this->taxBands($lines);
        }

        $taxable = $this->sum($lines, fn (Line $line): int => $line->netMinor);

        return $taxable === 0 ? [] : [['rate' => 0.0, 'taxable' => $taxable, 'tax' => 0]];
    }

    /**
     * Group line net by tax rate and compute the tax per band.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function taxBands(array $lines): array
    {
        $taxable = [];
        $rates = [];

        foreach ($lines as $line) {
            $key = $this->rate($line->taxRate);
            $taxable[$key] = ($taxable[$key] ?? 0) + $line->netMinor;
            $rates[$key] = $line->taxRate;
        }

        $bands = [];

        foreach ($taxable as $key => $sum) {
            $rate = $rates[$key];
            $bands[] = ['rate' => $rate, 'taxable' => $sum, 'tax' => (int) round($sum * $rate / 100)];
        }

        return $bands;
    }

    /**
     * Sum an integer projection over a list.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): int  $value
     */
    private function sum(array $items, callable $value): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += $value($item);
        }

        return $total;
    }

    /** A tax rate as a plain percentage string, e.g. 19.0 → "19", 25.5 → "25.5". */
    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
