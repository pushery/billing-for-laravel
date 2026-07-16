<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Models\InvoiceRecord;

/**
 * Renders a stored invoice as a structured electronic-invoice document (the EN 16931 / XRechnung XML
 * that German B2G/B2B increasingly mandates). The seller is the platform (config('billing.company'));
 * the buyer, line items and tax split come from the immutable invoice row. Kept behind a contract so
 * the plain XRechnung XML and a future ZUGFeRD (PDF/A-3 + embedded XML) writer are interchangeable.
 */
interface EInvoice
{
    /** The invoice rendered as an EN 16931-compliant XML document. */
    public function render(InvoiceRecord $invoice): string;
}
