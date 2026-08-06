<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Models\InvoiceRecord;

/**
 * Renders a stored invoice as a structured electronic-invoice document (the EN 16931 / XRechnung XML
 * that German B2G/B2B increasingly mandates). The seller comes from the invoice row's own frozen `seller`
 * snapshot, falling back to {@see SellerPartyResolver} — whose default is the platform — so a settlement
 * issued in a creator's name keeps naming the creator after the platform's own details change. The buyer,
 * line items and tax split likewise come from the immutable row. Kept behind a contract so the plain
 * XRechnung XML and the ZUGFeRD (PDF/A-3 + embedded XML) writer are interchangeable.
 */
interface EInvoice
{
    /** The invoice rendered as an EN 16931-compliant XML document. */
    public function render(InvoiceRecord $invoice): string;
}
