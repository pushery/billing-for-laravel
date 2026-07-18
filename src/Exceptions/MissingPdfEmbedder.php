<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a ZUGFeRD PDF/A-3 is requested but the optional PDF-embedding toolchain is not installed.
 * Embedding the e-invoice XML into a conformant PDF/A-3 needs a heavy PDF library the lean core does not
 * carry, so it is a `suggest` dependency; a consumer who wants the hybrid PDF opts in, and until then this
 * fails loudly and actionably rather than fataling on an undefined class.
 */
final class MissingPdfEmbedder extends RuntimeException
{
    public static function install(): self
    {
        return new self(
            'A ZUGFeRD PDF/A-3 needs the optional PDF embedder. Run `composer require horstoeko/zugferd`, '
            .'or use the CII XML (Pushery\Billing\Invoicing\ZugferdCiiInvoice) directly.'
        );
    }
}
