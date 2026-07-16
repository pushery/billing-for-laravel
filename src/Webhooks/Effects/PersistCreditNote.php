<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Events\InvoiceCredited;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\CreditNoteSnapshot;

/**
 * Persists an issued credit note as its own immutable {@see InvoiceRecord} — the accounting counterpart to
 * a refund. Without this, a refunded invoice left the books overstating turnover: the original charge was
 * recorded, the money going back was not, so DATEV and XRechnung had a debit with no matching credit.
 *
 * Idempotent on (provider, provider_id of the credit note): a redelivery converges to ONE row, exactly as
 * PersistInvoice does for the invoice. The row is linked back to the invoice it credits — by the local id
 * when that invoice was persisted, and always by the credited invoice's own number (falling back to the
 * provider's invoice id when the original was never stored locally), which is what an EN 16931 credit note
 * must reference (BG-3). The buyer is the invoice's frozen buyer — a credit note is issued to whoever the
 * invoice was — while the lines and amounts are the credit note's own, so a partial credit is faithful.
 *
 * The status is Refunded: the neutral marker that this document represents money credited back. The amounts
 * are positive; the credit-note nature, not a sign, inverts the accounting direction.
 */
final readonly class PersistCreditNote
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(InvoiceCredited $event): void
    {
        $snapshot = $event->creditNote;
        $owner = $this->directory->ownerForReference($snapshot->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own (another app on the same account) — nothing to store
        }

        $original = $this->creditedInvoice($snapshot);

        InvoiceRecord::query()->updateOrCreate(
            ['provider' => $snapshot->provider, 'provider_id' => $snapshot->providerId],
            $this->attributes($owner, $snapshot, $original),
        );
    }

    /** The local invoice this credit note credits, when it was persisted; null otherwise. */
    private function creditedInvoice(CreditNoteSnapshot $snapshot): ?InvoiceRecord
    {
        return InvoiceRecord::query()
            ->where('provider', $snapshot->provider)
            ->where('provider_id', $snapshot->creditsProviderId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $owner, CreditNoteSnapshot $snapshot, ?InvoiceRecord $original): array
    {
        // Always a reference to what is credited: the original's own number when we have it, the snapshot's
        // credited number next, and the provider's invoice id last — never null on a credit note.
        $originalNumber = $original?->number;

        return [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'number' => $snapshot->number,
            'total_minor' => $snapshot->totalMinor,
            'subtotal_minor' => $snapshot->subtotalMinor,
            'tax_minor' => $snapshot->taxMinor,
            'currency' => $snapshot->currency,
            'status' => InvoiceStatus::Refunded,
            'issued_at' => $snapshot->issuedAt !== null ? Carbon::createFromTimestamp($snapshot->issuedAt) : null,
            'credited_invoice_id' => $original?->getKey(),
            'credited_invoice_number' => $originalNumber ?? $snapshot->creditsNumber ?? $snapshot->creditsProviderId,
            // The tax treatment MUST match the credited invoice: a reverse-charge invoice's credit note is
            // itself reverse charge (VAT category AE, not Z). The frozen original is authoritative when we
            // stored it; otherwise trust the snapshot's own signal from the credit-note payload.
            'reverse_charge' => $original instanceof InvoiceRecord ? (bool) $original->reverse_charge : $snapshot->reverseCharge,
            // A credit note is issued to the invoice's buyer. Prefer the frozen original (the authoritative
            // §14 UStG buyer); fall back to whatever the snapshot carried when the original is unknown.
            'buyer' => $this->buyer($snapshot, $original),
            'lines' => $snapshot->lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(CreditNoteSnapshot $snapshot, ?InvoiceRecord $original): array
    {
        $fromOriginal = $original?->buyer;

        if (is_array($fromOriginal) && $fromOriginal !== []) {
            return $fromOriginal;
        }

        return $snapshot->buyer;
    }
}
