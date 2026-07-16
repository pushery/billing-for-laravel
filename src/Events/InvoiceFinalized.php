<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\InvoiceSnapshot;

/**
 * An invoice was finalized by the provider — it now legally exists, with its number and its frozen buyer,
 * lines and tax. The package persists it (see PersistInvoice) as the immutable record that XRechnung and
 * DATEV are rendered from; until this event started firing, that table was never written and e-invoicing
 * had no data source.
 *
 * Neutral by design: the Stripe mapper builds the snapshot from a Stripe invoice, and a future
 * Mollie/Adyen local engine builds it from its own. The effect that persists it never sees a provider
 * object.
 */
final readonly class InvoiceFinalized implements BillingDomainEvent, IdentifiesCustomer
{
    public string $customerReference;

    public function __construct(public InvoiceSnapshot $invoice)
    {
        $this->customerReference = $invoice->customerReference;
    }
}
