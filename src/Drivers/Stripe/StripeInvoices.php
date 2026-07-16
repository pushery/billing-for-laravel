<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Pushery\Billing\Contracts\Invoices as InvoicesContract;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\ValueObjects\Invoice;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Pushery\Billing\ValueObjects\InvoicePage;
use Pushery\Billing\ValueObjects\Money;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice as StripeInvoice;
use Stripe\StripeClient;

/**
 * Read access to a billable's Stripe invoices, hydrated into package Invoice DTOs so views render a
 * neutral shape. `download` streams the hosted PDF only after confirming the invoice belongs to the
 * billable's Stripe customer — an invoice id for another customer resolves to null, never a leak.
 */
final readonly class StripeInvoices implements InvoicesContract
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
    ) {}

    public function recent(Model $billable, int $perPage = 24): InvoicePage
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return new InvoicePage([], false);
        }

        $invoices = $this->stripe->invoices->all(['customer' => $customerId, 'limit' => $perPage]);

        $rows = [];

        foreach ($invoices->data as $invoice) {
            $id = $invoice->id;

            if (is_string($id)) {
                $rows[] = $this->toValue($invoice, $id);
            }
        }

        return new InvoicePage($rows, $invoices->has_more);
    }

    public function download(Model $billable, string $invoiceId): ?InvoiceDownload
    {
        $customerId = $this->customers->find($billable);

        if ($customerId === null) {
            return null;
        }

        try {
            $invoice = $this->stripe->invoices->retrieve($invoiceId);
        } catch (InvalidRequestException) {
            return null;
        }

        // Ownership guard: an invoice for a different customer is not visible.
        $owner = $invoice->customer ?? null;

        if (! is_string($owner) || $owner !== $customerId) {
            return null;
        }

        $url = $invoice->invoice_pdf ?? null;

        if (! is_string($url)) {
            return null;
        }

        $number = $invoice->number ?? null;

        return new InvoiceDownload(
            filename: ($number ?? $invoiceId).'.pdf',
            contents: Http::get($url)->body(),
        );
    }

    private function toValue(StripeInvoice $invoice, string $id): Invoice
    {
        return new Invoice(
            id: $id,
            date: new DateTimeImmutable('@'.$invoice->created),
            total: Money::of($invoice->total, strtoupper($invoice->currency)),
            status: $this->mapStatus($invoice->status),
            number: $invoice->number ?? null,
            downloadUrl: $invoice->invoice_pdf ?? null,
        );
    }

    private function mapStatus(?string $status): InvoiceStatus
    {
        return match ($status) {
            'paid' => InvoiceStatus::Paid,
            'draft' => InvoiceStatus::Draft,
            'uncollectible' => InvoiceStatus::Uncollectible,
            'void' => InvoiceStatus::Void,
            default => InvoiceStatus::Open,
        };
    }
}
