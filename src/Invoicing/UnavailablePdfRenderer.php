<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Contracts\PdfRenderer;
use Pushery\Billing\Exceptions\PdfRendererUnavailable;

/**
 * The default PdfRenderer binding: there is none, so it refuses loudly.
 *
 * Binding this rather than leaving the contract unbound means a PDF request fails with a clear, actionable
 * message ("bind a renderer") instead of a container resolution error. A consumer that wants PDFs binds a
 * real implementation (dompdf, Snappy, …) over this.
 */
final readonly class UnavailablePdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        throw PdfRendererUnavailable::notBound();
    }
}
