<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Pushery\Billing\ValueObjects\InvoicePage;

/**
 * The no-op Invoices reader bound when billing is disabled: with no provider and no local invoicing engine,
 * an owner has no invoices, so the list is empty and no document is downloadable — never a Stripe call.
 */
final class NullInvoices implements Invoices
{
    public function recent(Model $billable, int $perPage = 24): InvoicePage
    {
        return new InvoicePage([]);
    }

    public function download(Model $billable, string $invoiceId): ?InvoiceDownload
    {
        return null;
    }
}
