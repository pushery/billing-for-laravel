# Invoices and e-invoicing

Every invoice Stripe finalizes is persisted as an immutable `InvoiceRecord` — its number, its buyer snapshot
(name, address, VAT id, frozen at finalization as §14 UStG requires) and its line and tax breakdown — by a
webhook effect. That stored row is what the e-invoicing renderers read; nothing needs to be populated by
hand.

When money goes back, the correction Stripe issues (a Stripe credit note, `credit_note.created`) is persisted
the same way, linked to the invoice it corrects. DATEV books it as a Haben (credit), and the writer renders
it as an EN 16931 correcting document (type code 381 — EN 16931 names 381 "Credit note") referencing the
original invoice; amounts stay positive, the document type carries the correcting meaning. See
[Accounting and DATEV](accounting-and-datev.md) for the booking side.

Render a stored invoice as an EN 16931 document, or export a batch to DATEV:

```php
// XRechnung — the standalone UBL syntax (the EInvoice default), for B2G / Leitweg-ID:
$ubl = app(Pushery\Billing\Contracts\EInvoice::class)->render($invoice);

// ZUGFeRD / Factur-X — the UN/CEFACT CII syntax a hybrid PDF/A-3 embeds:
$cii = app(Pushery\Billing\Invoicing\ZugferdCiiInvoice::class)->render($invoice);

$csv = app(Pushery\Billing\Invoicing\DatevExport::class)
    ->export($invoices, $from, $to);
```

Both syntaxes are built from one normalized invoice model, so a standard sale, an intra-EU reverse charge, a
correction (type 381) or a multi-rate split can never drift between them. For the reverse-charge rules that
govern when a zero rate is applied, see [Taxes](taxes.md).

Set your company details under `config('billing.company')` and your DATEV account numbers under
`config('billing.datev')`.

## The hybrid ZUGFeRD / Factur-X PDF/A-3

To ship the hybrid **ZUGFeRD / Factur-X** document — the CII XML embedded in a human-readable PDF/A-3 —
`Invoicing\ZugferdPdfInvoice::embed()` merges the XML into your own rendered invoice PDF and returns the
PDF/A-3 bytes:

```php
// $yourInvoicePdf is your app's own rendered invoice (binary content) — the human-readable page the
// structured XML is embedded into. Pass your own document, never untrusted input.
$pdf = app(Pushery\Billing\Invoicing\ZugferdPdfInvoice::class)
    ->embed($invoice, $yourInvoicePdf);
```

A conformant PDF/A-3 needs a real PDF toolchain the lean core does not carry, so this one helper is
**opt-in**: `composer require horstoeko/zugferd` (a `suggest` dependency). Without it `embed()` throws a clear
`MissingPdfEmbedder` instead of fataling — and you can still ship the CII or XRechnung XML on its own, which
needs no PDF library.

---

[← Back to the documentation index](../README.md)
