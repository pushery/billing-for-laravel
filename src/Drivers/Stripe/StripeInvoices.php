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
            // READ THROUGH ARRAY ACCESS, NOT THE MAGIC PROPERTY, and the difference is the whole point.
            //
            // `Stripe\Invoice::$id` is a docblock `@property string $id` on every major -- there is no
            // declared property, and `StripeObject::&__get()` answers null for a key the payload does
            // not carry. Measured on the installed v20.3.1: a fresh Invoice with no id key returns null
            // and emits "Stripe Notice: Undefined property".
            //
            // So the value CAN be null here, and a guard against it is not dead code. PHPStan thought it
            // was, because it believes the docblock -- and this line used to carry no guard for exactly
            // that reason. Array access hands back the same value without the overstated type, which
            // lets the check be written as what it is.
            //
            // A floor on the installed major used to stand in for this and could not deliver it: 18.0
            // narrowed the docblock, not the behavior.
            $id = $invoice['id'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $rows[] = $this->toValue($invoice, $id);
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
