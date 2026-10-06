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
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Invoice as StripeInvoice;
use Stripe\StripeClient;

/**
 * Read access to a billable's Stripe invoices, hydrated into package Invoice DTOs so views render a
 * neutral shape. `download` streams the hosted PDF only after confirming the invoice belongs to the
 * billable's Stripe customer — an invoice id for another customer resolves to null, never a leak. A PDF host
 * that answers with an error throws rather than handing its error page over as the invoice.
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
            // not carry, and emits "Stripe Notice: Undefined property" while it does.
            //
            // So the value CAN be null here, and a guard against it is not dead code, though PHPStan, which
            // believes the docblock, would call it that. Array access hands back the same value without the
            // overstated type, which lets the check be written as what it is.
            //
            // A floor on the installed major cannot stand in for this: 18.0 narrowed the docblock, not the
            // behavior.
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

        // The HTTP client returns a 404 or a 503 like a success, and its body would go to the customer as the
        // invoice, named after it, with status 200. An outage is not "no such invoice", which is what null
        // says here, so it throws and reaches the exception handler, as a rate limit above does.
        return new InvoiceDownload(
            filename: ($number ?? $invoiceId).'.pdf',
            contents: Http::get($url)->throw()->body(),
        );
    }

    private function toValue(StripeInvoice $invoice, string $id): Invoice
    {
        return new Invoice(
            id: $id,
            date: new DateTimeImmutable('@'.$invoice->created),
            total: StripeAmount::toMoney($invoice->total, strtoupper($invoice->currency)),
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
