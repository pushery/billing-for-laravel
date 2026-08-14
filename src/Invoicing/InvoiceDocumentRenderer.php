<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\PdfRenderer;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * Renders one of the package's own invoices to a human-readable document — the local counterpart to a
 * provider's hosted invoice PDF, for a driver that supplies none.
 *
 * It has two stages, and the split is the point: html() produces a complete, deterministic HTML document
 * from the invoice row and a publishable Blade template, with no browser and no PDF toolchain involved — so
 * it is fast and snapshot-testable. pdf() hands that HTML to the PdfRenderer seam, which a consumer binds to
 * an actual toolchain. Nothing here reaches a provider; the document is built entirely from the local row.
 */
final readonly class InvoiceDocumentRenderer
{
    public function __construct(
        private ViewFactory $views,
        private PdfRenderer $pdf,
        private MarginDocumentGuard $margins,
        private SellerPartyResolver $sellers,
    ) {}

    /** The prescribed wording, from the jurisdiction, or nothing where none supplies any. */
    private function marginNote(): ?string
    {
        $key = $this->margins->wordingKey();

        return $key === null ? null : (string) Lang::get($key);
    }

    /** The invoice as a complete HTML document — deterministic, browser-free, and testable as a snapshot. */
    public function html(InvoiceRecord $invoice): string
    {
        return $this->views->make('billing::invoice', $this->data($invoice))->render();
    }

    /** The invoice as PDF bytes, via the bound PdfRenderer. Throws PdfRendererUnavailable if none is bound. */
    public function pdf(InvoiceRecord $invoice): string
    {
        return $this->pdf->render($this->html($invoice));
    }

    /**
     * The view data. Money is formatted here (not in the template) so the template stays presentation-only
     * and the amounts are computed once. A line's own net is used; the document net/tax/total come from the
     * stored figures, which are authoritative.
     *
     * @return array<string, mixed>
     */
    private function data(InvoiceRecord $invoice): array
    {
        $currency = $invoice->currency;
        $lines = $this->lines($invoice, $currency);

        $subtotal = $invoice->subtotal_minor ?? ($invoice->total_minor - ($invoice->tax_minor ?? 0));
        $tax = $invoice->tax_minor ?? 0;

        // A short receipt states the gross with its rate in ONE sum and names nobody. Splitting it into net
        // and tax, or printing an empty recipient block, would make a document that is deliberately
        // anonymous look like an incomplete invoice — and a reader would go looking for the missing name.
        $itemised = $invoice->receipt_tier?->itemisesTax() ?? true;

        // A margin-taxed document states NO tax and no rate — stating either is treated as a separate
        // statement of tax, which the seller then owes on top of what they already owe on the margin. The
        // prescribed wording is what makes the document legible as margin-taxed; without it the same page
        // reads as an ordinary sale that forgot its tax, and a buyer may ask for a figure that must never
        // be given.
        $margin = $invoice->taxation_basis?->taxesMarginOnly() ?? false;

        if ($margin) {
            $this->margins->assertNoStatedTax($invoice);
            $itemised = false;
        }

        return [
            'marginScheme' => $margin,
            'marginNote' => $margin ? $this->marginNote() : null,
            'seller' => $this->seller($invoice)->toArray(),
            'buyer' => $itemised && is_array($invoice->buyer) ? $invoice->buyer : [],
            'itemisesTax' => $itemised,
            // No rate at all on a margin document: naming the rate is itself a statement of tax.
            'taxRate' => $margin ? null : $this->rateLabel($invoice),
            'number' => $invoice->number ?? (string) $invoice->id,
            'issuedAt' => $invoice->issued_at ?? $invoice->created_at,
            'isCorrection' => $invoice->isCorrection(),
            // The two statements the machine-readable half has always carried and this one did not. Read
            // from the same source the XML writers read, so the halves of one document cannot drift apart
            // again -- which they did, silently, because only one half is checked by a validator.
            'selfBilled' => $invoice->isSelfBilled(),
            'correctsNumber' => $invoice->credited_invoice_number,
            'reverseCharge' => (bool) $invoice->reverse_charge,
            'vatNote' => is_string($invoice->vat_note) ? $invoice->vat_note : null,
            'lines' => $lines,
            'subtotal' => Money::of($subtotal, $currency)->format(),
            'tax' => Money::of($tax, $currency)->format(),
            'total' => Money::of($invoice->total_minor, $currency)->format(),
        ];
    }

    /** The single rate a short receipt states beside its gross, or null where the document itemises. */
    private function rateLabel(InvoiceRecord $invoice): ?string
    {
        $bps = $invoice->tax_rate_bps;

        return $bps === null ? null : rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * @return list<array{description: string, quantity: string, unitPrice: string, net: string, rate: string}>
     */
    private function lines(InvoiceRecord $invoice, string $currency): array
    {
        $raw = $invoice->getAttribute('lines');
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $parsed = Line::fromArray($line);
            $out[] = [
                'description' => $parsed->description,
                'quantity' => $parsed->quantity,
                'unitPrice' => Money::of($parsed->unitPriceMinor, $currency)->format(),
                'net' => Money::of($parsed->netMinor, $currency)->format(),
                'rate' => rtrim(rtrim(number_format($parsed->taxRate, 2, '.', ''), '0'), '.').'%',
            ];
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    /**
     * The seller named on this document, resolved the way the XML writers resolve it.
     *
     * This used to be `config('billing.company')` outright, and the two halves of a hybrid ZUGFeRD document
     * therefore disagreed: `ZugferdPdfInvoice` embeds `ZugferdCiiInvoice`'s XML -- which reads the frozen
     * per-document `seller` snapshot -- into the PDF this class renders, which named the platform whatever
     * the row said. On a self-billed settlement the visible page named the platform while the machine-readable
     * half named the creator, in one file, about the one fact the document exists to state.
     *
     * A hybrid format exists so that a person and a machine read the SAME invoice. Two answers to "who
     * supplied this" is not an imprecision; which one counts depends on which software opens the file.
     *
     * Unchanged for a document with no snapshot: the resolver's default is the platform company, so every
     * single-seller invoice renders byte-for-byte what it did before.
     */
    private function seller(InvoiceRecord $invoice): Party
    {
        $snapshot = $invoice->getAttribute('seller');

        if (is_array($snapshot)) {
            return Party::fromArray($snapshot);
        }

        return $this->sellers->sellerFor($invoice);
    }
}
