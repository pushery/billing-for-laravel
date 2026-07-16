<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\CreditNoteSnapshot;

/**
 * A credit note was issued against a finalized invoice — money went back (a refund, or a dispute the
 * merchant lost), and the provider recorded the accounting document for it. The package persists it (see
 * PersistCreditNote) as its own credit-note row, linked to the invoice it credits, so DATEV books it as a
 * Haben and XRechnung renders it as an EN 16931 credit note (type 381).
 *
 * This is the accounting counterpart to {@see AddonRefunded}, which handles the money side (clawing back a
 * one-time add-on's credit). The two are deliberately separate: the refund moves the money, the credit note
 * is the document that accounts for it — and a provider issues the credit note with proper lines and tax
 * that a raw refund event does not carry.
 *
 * Neutral by design: the Stripe mapper builds the snapshot from a Stripe credit note, and a future
 * Mollie/Adyen local engine builds it from its own. The effect that persists it never sees a provider
 * object.
 */
final readonly class InvoiceCredited implements BillingDomainEvent, IdentifiesCustomer
{
    public string $customerReference;

    public function __construct(public CreditNoteSnapshot $creditNote)
    {
        $this->customerReference = $creditNote->customerReference;
    }
}
