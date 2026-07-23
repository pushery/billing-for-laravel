<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\InvoiceCorrectionSnapshot;

/**
 * An invoice correction was issued against a finalized invoice — money went back (a refund, or a dispute
 * the merchant lost) or an earlier invoice was amended, and the provider recorded the accounting document
 * for it. The package persists it (see PersistInvoiceCorrection) as its own correction row, linked to the
 * invoice it corrects, so DATEV books it as a Haben and the e-invoice writer renders it as an EN 16931
 * correcting document (type code 381 for a cancellation, 384 for an amendment).
 *
 * This is the accounting counterpart to {@see AddonRefunded}, which handles the money side (clawing back a
 * one-time add-on's credit). The two are deliberately separate: the refund moves the money, the correction
 * is the document that accounts for it — and a provider issues it with proper lines and tax that a raw
 * refund event does not carry.
 *
 * Neutral by design: the Stripe mapper builds the snapshot from a Stripe credit note, and a future
 * Mollie/Adyen local engine builds it from its own. The effect that persists it never sees a provider
 * object.
 *
 * Renamed from `InvoiceCredited` (the old name conflated this correcting document with a self-billing
 * credit note). For one deprecation window it still fires under the old name for host listeners — see
 * {@see HasDeprecatedAlias}.
 */
final readonly class InvoiceCorrected implements BillingDomainEvent, HasDeprecatedAlias, IdentifiesCustomer
{
    public string $customerReference;

    public function __construct(public InvoiceCorrectionSnapshot $correction)
    {
        $this->customerReference = $correction->customerReference;
    }

    public function deprecatedAlias(): BillingDomainEvent
    {
        return new InvoiceCredited($this->correction);
    }
}
