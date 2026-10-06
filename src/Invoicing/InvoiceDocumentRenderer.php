<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Traits\Localizable;
use Pushery\Billing\Contracts\PdfRenderer;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\LocalizedMoney;
use Pushery\Billing\Support\LocalizedNumber;
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
    use Localizable;

    public function __construct(
        private ViewFactory $views,
        private PdfRenderer $pdf,
        private MarginDocumentGuard $margins,
        private SellerPartyResolver $sellers,
    ) {}

    /** The prescribed wording for this document's goods class, or nothing where the jurisdiction supplies none. */
    private function marginNote(TaxationBasis $basis): ?string
    {
        $key = $this->margins->wordingKey($basis);

        return $key === null ? null : (string) Lang::get($key);
    }

    /**
     * The invoice as a complete HTML document — deterministic, browser-free, and testable as a snapshot.
     *
     * Rendered in the language the document was written in, whoever opens it and in whatever language they use the
     * rest of the application. A document from before that language was kept renders in the current one.
     */
    public function html(InvoiceRecord $invoice): string
    {
        $render = fn (): string => $this->views->make('billing::invoice', $this->data($invoice))->render();

        if ($invoice->locale === null) {
            return $render();
        }

        return $this->withLocale($invoice->locale, $render);
    }

    /** The invoice as PDF bytes, via the bound PdfRenderer. Throws PdfRendererUnavailable if none is bound. */
    public function pdf(InvoiceRecord $invoice): string
    {
        return $this->pdf->render($this->html($invoice));
    }

    /** Whether a PDF toolchain is bound at all, so a screen can offer a download before anybody asks for one. */
    public function rendersPdf(): bool
    {
        return ! $this->pdf instanceof UnavailablePdfRenderer;
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
        // The language the document is written in, which html() has made the current one. Its labels and its
        // `lang` attribute come from the same locale, so every amount and rate on it is written in that language too.
        $locale = Lang::getLocale();
        $lines = $this->lines($invoice, $currency, $locale);

        // An amount collected on behalf of another party is part of what was paid and none of the issuer's supply, so
        // the rate and the tax are stated over the rest, and the amount stands apart between them and the total.
        $collected = $this->collectedMinor($invoice);
        $subtotal = $invoice->subtotal_minor ?? ($invoice->total_minor - ($invoice->tax_minor ?? 0) - $collected);
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

        // Two statements rather than a ternary inside the array below, and the repository refuses the
        // ternary for a measured reason: pcov cannot mark both arms of a multi-line one, so a `?:` there
        // takes the file under the coverage floor with no test able to lift it.
        $marginNote = null;

        if ($margin && $invoice->taxation_basis instanceof TaxationBasis) {
            $marginNote = $this->marginNote($invoice->taxation_basis);
        }

        return [
            'marginScheme' => $margin,
            'marginNote' => $marginNote,
            'seller' => $this->seller($invoice)->toArray(),
            'buyer' => $itemised && is_array($invoice->buyer) ? $invoice->buyer : [],
            'itemisesTax' => $itemised,
            // No rate at all on a margin document: naming the rate is itself a statement of tax.
            'taxRate' => $margin ? null : $this->rateLabel($invoice, $locale),
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
            'subtotal' => LocalizedMoney::format(Money::of($subtotal, $currency), $locale),
            'tax' => LocalizedMoney::format(Money::of($tax, $currency), $locale),
            'total' => LocalizedMoney::format(Money::of($invoice->total_minor, $currency), $locale),
            'collected' => $collected === 0 ? null : LocalizedMoney::format(Money::of($collected, $currency), $locale),
            'supplyTotal' => LocalizedMoney::format(Money::of($invoice->total_minor - $collected, $currency), $locale),
        ];
    }

    /**
     * What the document's lines collected on behalf of another party, in minor units, where the document states it apart.
     *
     * Apart exactly where the e-invoice of the same document states it in a band of its own. On a document that is
     * exempt, margin-taxed or outside the scope as a whole the amount belongs to the document's one band, and the page
     * says no more than the machine-readable half does.
     */
    private function collectedMinor(InvoiceRecord $invoice): int
    {
        $raw = $invoice->getAttribute('lines');
        $collected = 0;

        foreach (is_array($raw) ? $raw : [] as $line) {
            $parsed = is_array($line) ? Line::fromArray($line) : null;

            if ($parsed?->collectedOnBehalf() === true) {
                $collected += $parsed->netMinor;
            }
        }

        if ($collected === 0 || ! EnInvoiceTaxCategory::forDocument($invoice, null)->isCollectedOnBehalf()) {
            return 0;
        }

        return $collected;
    }

    /** The single rate a short receipt states beside its gross, or null where the document itemises. */
    private function rateLabel(InvoiceRecord $invoice, string $locale): ?string
    {
        $bps = $invoice->tax_rate_bps;

        return $bps === null ? null : LocalizedNumber::percent($bps / 100, $locale);
    }

    /**
     * @return list<array{description: string, quantity: string, unitPrice: string, net: string, rate: string}>
     */
    private function lines(InvoiceRecord $invoice, string $currency, string $locale): array
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
                'unitPrice' => LocalizedMoney::format(Money::of($parsed->unitPriceMinor, $currency), $locale),
                'net' => LocalizedMoney::format(Money::of($parsed->netMinor, $currency), $locale),
                // A dash where the line has no rate: an amount collected on behalf of another party is not taxed at 0 %.
                'rate' => $parsed->taxRate === null ? '—' : LocalizedNumber::percent($parsed->taxRate, $locale),
            ];
        }

        return $out;
    }

    /**
     * The seller named on this document, resolved the way the XML writers resolve it.
     *
     * Not `config('billing.company')` outright, because the two halves of a hybrid ZUGFeRD document would then
     * disagree: `ZugferdPdfInvoice` embeds `ZugferdCiiInvoice`'s XML -- which reads the frozen per-document
     * `seller` snapshot -- into the PDF this class renders, which would name the platform whatever the row
     * said. On a self-billed settlement the visible page would name the platform while the machine-readable
     * half named the creator, in one file, about the one fact the document exists to state.
     *
     * A hybrid format exists so that a person and a machine read the SAME invoice. Two answers to "who
     * supplied this" is not an imprecision; which one counts depends on which software opens the file.
     *
     * For a document with no snapshot the resolver's default is the platform company, so a single-seller
     * invoice names the company configured in `billing.company`.
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
