<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use Pushery\Billing\Exceptions\MissingPdfEmbedder;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Embeds the EN 16931 CII XML into a PDF/A-3 — the hybrid ZUGFeRD / Factur-X document: a human-readable
 * invoice PDF that machine-readable software reads the structured invoice from. The XML comes from
 * {@see ZugferdCiiInvoice}; the source PDF is the consuming app's own rendered invoice.
 *
 * A conformant PDF/A-3 (OutputIntent, XMP, the embedded-file spec) needs a real PDF toolchain, so this is an
 * OPTIONAL capability the lean core does not carry: install `horstoeko/zugferd` (a `suggest` dependency) to
 * use it. Without it, {@see embed()} throws a clear {@see MissingPdfEmbedder} instead of fataling — a
 * consumer who needs the hybrid PDF opts in, everyone else ships the CII XML alone. The class is NOT final so
 * the embedder-availability check ({@see embedderInstalled()}) is an overridable seam, which is how the
 * absent-library path stays testable.
 */
class ZugferdPdfInvoice
{
    public function __construct(private readonly ZugferdCiiInvoice $cii) {}

    /**
     * Embed the invoice's CII XML into the given source PDF and return the ZUGFeRD PDF/A-3 bytes.
     *
     * @param  string  $sourcePdf  your own rendered invoice PDF to embed into — binary content, or a path that
     *                             the merger reads from the LOCAL filesystem. Never pass untrusted input as a
     *                             path: a caller-controlled path is a local-file-read primitive. Prefer bytes.
     *
     * @throws MissingPdfEmbedder when the optional horstoeko/zugferd toolchain is not installed
     */
    public function embed(InvoiceRecord $invoice, string $sourcePdf): string
    {
        if (! $this->embedderInstalled()) {
            throw MissingPdfEmbedder::install();
        }

        return new ZugferdDocumentPdfMerger($this->cii->render($invoice), $sourcePdf)
            ->generateDocument()
            ->downloadString();
    }

    /** Whether the optional horstoeko/zugferd PDF/A-3 embedder is installed — a seam so the absent path is testable. */
    protected function embedderInstalled(): bool
    {
        return class_exists(ZugferdDocumentPdfMerger::class);
    }
}
