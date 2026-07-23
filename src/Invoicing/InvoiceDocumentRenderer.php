<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Pushery\Billing\Contracts\PdfRenderer;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * Renders one of the package's own invoices to a human-readable document — the local counterpart to a
 * provider's hosted invoice PDF, for a driver (Mollie) that supplies none.
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
        private Repository $config,
        private PdfRenderer $pdf,
    ) {}

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

        return [
            'seller' => $this->companyArray('billing.company'),
            'buyer' => is_array($invoice->buyer) ? $invoice->buyer : [],
            'number' => $invoice->number ?? (string) $invoice->id,
            'issuedAt' => $invoice->issued_at ?? $invoice->created_at,
            'isCorrection' => $invoice->isCorrection(),
            'reverseCharge' => (bool) $invoice->reverse_charge,
            'vatNote' => is_string($invoice->vat_note) ? $invoice->vat_note : null,
            'lines' => $lines,
            'subtotal' => Money::of($subtotal, $currency)->format(),
            'tax' => Money::of($tax, $currency)->format(),
            'total' => Money::of($invoice->total_minor, $currency)->format(),
        ];
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
    private function companyArray(string $key): array
    {
        $value = $this->config->get($key);

        return is_array($value) ? $value : [];
    }
}
