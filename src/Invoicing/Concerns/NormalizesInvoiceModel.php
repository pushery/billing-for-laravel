<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Concerns;

use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Exceptions\InvalidInvoiceCorrection;
use Pushery\Billing\Invoicing\Line;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * The syntax-agnostic invoice model shared by every EN 16931 writer: the seller and buyer parties, the
 * line items, and the per-rate tax breakdown. UBL (XRechnung) and CII (ZUGFeRD) are two syntaxes over
 * the SAME semantic model, so this normalization lives once — a tax band computed one way for XRechnung
 * and another for ZUGFeRD would be a silent conformance drift between the two outputs of one invoice.
 *
 * The consuming class must expose a {@see SellerPartyResolver} through `sellerPartyResolver()`; its default
 * names the platform, so a single-seller document is unchanged.
 */
trait NormalizesInvoiceModel
{
    abstract private function sellerPartyResolver(): SellerPartyResolver;

    /**
     * The seller named on this document.
     *
     * Read from the frozen per-document snapshot when there is one, so a document keeps naming whoever it
     * named when it was issued even after the config or the merchant changes. A row written before the
     * snapshot column existed has none and falls back to the resolver — whose default is the platform
     * company, byte-identical to what these writers produced before the seller was resolvable.
     */
    private function seller(InvoiceRecord $invoice): Party
    {
        $snapshot = $invoice->getAttribute('seller');

        if (is_array($snapshot)) {
            return Party::fromArray($snapshot);
        }

        return $this->sellerPartyResolver()->sellerFor($invoice);
    }

    private function buyer(InvoiceRecord $invoice): Party
    {
        $buyer = $invoice->getAttribute('buyer');

        return Party::fromArray(is_array($buyer) ? $buyer : []);
    }

    /**
     * The EN 16931 document type code (BT-3), shared by both writers so the two syntaxes never disagree.
     *
     * Four codes, selected by the document's ROLE rather than by a boolean. A cancellation is 381 and an
     * amendment is 384 — two different documents a tax authority reads apart by this code alone, which is
     * exactly why one boolean could never carry both. A self-billed invoice is 389, a document treated
     * differently from the ordinary 380. Correction wins first: correcting a self-billed invoice produces a
     * correction, not another self-bill. In every case the code, not a negative amount, carries the
     * correcting meaning, so the amounts stay positive.
     *
     * A row whose kind was never recorded is a cancellation, which is what every correction written before
     * the two roles existed is — so the code such a row renders is the code it always rendered.
     *
     * An amendment MUST name what it amends (BR-55), and the check sits here rather than only on the model
     * that creates one: a row that reached the database without a reference — an older row, an import, a
     * direct write — would otherwise be serialized into a document that is invalid the moment it is read,
     * and the reader is a tax authority. Refusing to write it is the only honest outcome.
     */
    private function typeCode(InvoiceRecord $invoice): string
    {
        if (! $invoice->isCorrection()) {
            return $invoice->settlement_document_type === SettlementDocumentType::SelfBilledInvoice ? '389' : '380';
        }

        if ($invoice->correction_kind !== InvoiceCorrectionKind::Amendment) {
            return '381';
        }

        $origin = $invoice->credited_invoice_number;

        if ($origin === null || $origin === '') {
            throw InvalidInvoiceCorrection::amendmentWithoutReference();
        }

        return '384';
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
     * A one-stop-shop supply is banded at the rate the SALE was declared under, read from the invoice's own
     * frozen column rather than from the lines. The lines carry a rate derived at pricing time from a product
     * that can be reclassified afterwards; the column is what the return was filed on. Re-rendering a
     * document from the lines could therefore state a rate the platform never declared for that sale, into a
     * country it never declared it to — and the document would still add up.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function taxBandsFor(array $lines, bool $reverseCharge, ?InvoiceRecord $invoice = null): array
    {
        if (! $reverseCharge) {
            $declared = $this->declaredOssRate($invoice);

            return $declared === null ? $this->taxBands($lines) : $this->singleBand($lines, $declared);
        }

        return $this->singleBand($lines, 0.0);
    }

    /**
     * The rate a one-stop-shop supply was declared at, or null when the invoice is not one.
     *
     * Both the flag and the rate are required: a document flagged as such but carrying no rate cannot say
     * what it was declared at, and falling back to the lines there would silently produce the very
     * re-derivation this exists to prevent.
     */
    private function declaredOssRate(?InvoiceRecord $invoice): ?float
    {
        if (! $invoice instanceof InvoiceRecord || ! (bool) $invoice->oss) {
            return null;
        }

        $rate = $invoice->oss_rate;

        return is_numeric($rate) ? (float) $rate : null;
    }

    /**
     * One band over the whole taxable base at a single rate.
     *
     * The tax is the DIFFERENCE from the invoice's own total elsewhere; here it is the rate applied to the
     * base, which is what a breakdown states. An empty base emits no band at all — a zero-value band is not
     * a statement EN 16931 has a shape for.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function singleBand(array $lines, float $rate): array
    {
        $taxable = $this->sum($lines, fn (Line $line): int => $line->netMinor);

        if ($taxable === 0) {
            return [];
        }

        return [['rate' => $rate, 'taxable' => $taxable, 'tax' => (int) round($taxable * $rate / 100)]];
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

    /**
     * The note a self-billed document has to carry, or null where it is not one.
     *
     * A document the buyer wrote about the seller's own supply is only a valid invoice if it SAYS so. Read
     * without the statement it looks like an ordinary invoice issued by the wrong party, and the recipient
     * has no way to tell that it was written under an arrangement they agreed to. The wording is a
     * jurisdiction's, so it comes from the translations rather than from here.
     */
    private function selfBillingNote(InvoiceRecord $invoice): ?string
    {
        return $this->typeCode($invoice) === '389'
            ? (string) Lang::get('billing::invoice.self_billed_note')
            : null;
    }
}
