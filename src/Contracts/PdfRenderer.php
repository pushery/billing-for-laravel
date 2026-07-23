<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Turns a rendered HTML document into PDF bytes.
 *
 * This is a seam, not a bundled dependency: a PDF toolchain (dompdf, Snappy/wkhtmltopdf, a headless
 * browser) is heavy and opinionated, and a lean package should not force one on every consumer. The package
 * produces the invoice HTML itself — deterministic and testable without a browser — and hands it here only
 * when an actual PDF is wanted. A consumer that needs PDFs binds an implementation; everyone else works with
 * the HTML stage and never pulls in a renderer.
 */
interface PdfRenderer
{
    /** Render a complete HTML document to PDF bytes. */
    public function render(string $html): string;
}
