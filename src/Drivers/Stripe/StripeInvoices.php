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
use Stripe\Exception\RateLimitException;
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
            // No null guard on the id, and the reason is a version boundary rather than an oversight.
            // stripe-php declared `null|string $id` up to 17.x and narrowed it to `string` in 18.0.
            // This package now requires ^17.4|^18.0|^19.0|^20.0 and composer resolves that to the
            // highest allowed major, so statics run against the 18+ shape: a guard here would be dead
            // code that PHPStan reports as such (`is_string() will always evaluate to true`).
            $rows[] = $this->toValue($invoice, $invoice->id);
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
        } catch (RateLimitException $e) {
            // A 429 is TRANSIENT and only lands here because the SDK makes RateLimitException a
            // subclass of InvalidRequestException. Swallowing it files "try again" as "never".
            throw $e;
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
