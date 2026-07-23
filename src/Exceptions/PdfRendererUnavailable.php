<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A PDF was requested but no PdfRenderer has been bound.
 *
 * The package renders the invoice HTML itself but does not ship a PDF toolchain — that is a deliberate,
 * opt-in dependency. This exception says so clearly, rather than fataling on a missing class deep in a
 * third-party library, so a consumer who wants PDFs knows exactly what to bind.
 */
final class PdfRendererUnavailable extends RuntimeException
{
    public static function notBound(): self
    {
        return new self(
            'A PDF was requested, but no PdfRenderer is bound. The package renders the invoice HTML itself '.
            'and leaves the PDF step to you, because a PDF toolchain (dompdf, Snappy, a headless browser) is '.
            'a heavy, opinionated dependency a lean package should not force on every install. Bind an '.
            'implementation of Pushery\\Billing\\Contracts\\PdfRenderer to enable PDF downloads, or use the '.
            'HTML the renderer produces.'
        );
    }
}
