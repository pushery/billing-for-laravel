<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A rendered invoice document ready to stream to the browser — the neutral return of
 * Invoices::download(). The controller wraps it in an HTTP response; the contract stays free of any
 * HTTP-layer dependency.
 */
final readonly class InvoiceDownload
{
    public function __construct(
        public string $filename,
        public string $contents,
        public string $mimeType = 'application/pdf',
    ) {}
}
