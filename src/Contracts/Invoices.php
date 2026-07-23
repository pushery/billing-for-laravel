<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Pushery\Billing\ValueObjects\InvoicePage;

/**
 * Read access to an owner's invoices. `recent` returns a neutral page of package Invoice DTOs (the
 * Stripe driver hydrates them from Stripe invoices; the local engine builds them);
 * `download` returns the rendered document, HTTP-layer-free, for a controller to stream.
 */
interface Invoices
{
    public function recent(Model $billable, int $perPage = 24): InvoicePage;

    /** The rendered invoice document, or null when the invoice is not owned by / visible to the billable. */
    public function download(Model $billable, string $invoiceId): ?InvoiceDownload;
}
