<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Events\InvoiceCorrected;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\InvoiceCorrectionSnapshot;

/**
 * Persists an issued invoice correction as its own immutable {@see InvoiceRecord} — the accounting
 * counterpart to a refund. Without this, a refunded invoice left the books overstating turnover: the
 * original charge was recorded, the money going back was not, so DATEV and the e-invoice had a debit with
 * no matching credit.
 *
 * Idempotent on (provider, provider_id of the correction): a redelivery converges to ONE row, exactly as
 * PersistInvoice does for the invoice. The row is linked back to the invoice it corrects — by the local id
 * when that invoice was persisted, and always by the corrected invoice's own number (falling back to the
 * provider's invoice id when the original was never stored locally), which is what an EN 16931 correcting
 * document must reference (BG-3). The buyer is the invoice's frozen buyer — a correction is issued to
 * whoever the invoice was — while the lines and amounts are the correction's own, so a partial correction
 * is faithful.
 *
 * The status is Refunded: the neutral marker that this document represents money credited back. The amounts
 * are positive; the correction's nature, not a sign, inverts the accounting direction.
 */
final readonly class PersistInvoiceCorrection
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(InvoiceCorrected $event): void
    {
        $snapshot = $event->correction;
        $owner = $this->directory->ownerForReference($snapshot->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own (another app on the same account) — nothing to store
        }

        $original = $this->correctedInvoice($snapshot);

        InvoiceRecord::query()->updateOrCreate(
            ['provider' => $snapshot->provider, 'provider_id' => $snapshot->providerId],
            $this->attributes($owner, $snapshot, $original),
        );
    }

    /** The local invoice this correction corrects, when it was persisted; null otherwise. */
    private function correctedInvoice(InvoiceCorrectionSnapshot $snapshot): ?InvoiceRecord
    {
        return InvoiceRecord::query()
            ->where('provider', $snapshot->provider)
            ->where('provider_id', $snapshot->creditsProviderId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $owner, InvoiceCorrectionSnapshot $snapshot, ?InvoiceRecord $original): array
    {
        // Always a reference to what is corrected: the original's own number when we have it, the snapshot's
        // corrected number next, and the provider's invoice id last — never null on a correction.
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
            // The tax treatment MUST match the corrected invoice: a reverse-charge invoice's correction is
            // itself reverse charge (VAT category AE, not Z). The frozen original is authoritative when we
            // stored it; otherwise trust the snapshot's own signal from the correction payload.
            'reverse_charge' => $original instanceof InvoiceRecord ? (bool) $original->reverse_charge : $snapshot->reverseCharge,
            // A correction is issued to the invoice's buyer. Prefer the frozen original (the authoritative
            // §14 UStG buyer); fall back to whatever the snapshot carried when the original is unknown.
            'buyer' => $this->buyer($snapshot, $original),
            'lines' => $snapshot->lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(InvoiceCorrectionSnapshot $snapshot, ?InvoiceRecord $original): array
    {
        $fromOriginal = $original?->buyer;

        if (is_array($fromOriginal) && $fromOriginal !== []) {
            return $fromOriginal;
        }

        return $snapshot->buyer;
    }
}
