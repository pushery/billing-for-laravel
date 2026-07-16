<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\InvoiceFinalized;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\InvoiceSnapshot;

/**
 * Persists a finalized invoice as the package's own immutable record — the row XRechnung and DATEV render
 * from. Nothing wrote this table before; e-invoicing had a renderer and no data.
 *
 * Idempotent on (provider, provider_id): a redelivery, or a finalized-then-paid pair of webhooks for the
 * same invoice, converges to ONE row. updateOrCreate keyed on that pair is the dedup — the first webhook
 * creates the record, a later one (a status change to paid, a corrected total) updates it. The buyer, the
 * lines and the tax split are frozen from the snapshot: a valid invoice must carry the buyer (§14 UStG),
 * and an issued invoice must not change, so these are written once and read forever.
 *
 * The number is the provider's own invoice number, never one we mint: Stripe already assigns a gapless,
 * unique number, and a second number of our own for the same invoice would be worse than none.
 */
final readonly class PersistInvoice
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(InvoiceFinalized $event): void
    {
        $snapshot = $event->invoice;
        $owner = $this->directory->ownerForReference($snapshot->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own (another app on the same account) — nothing to store
        }

        $record = InvoiceRecord::query()->firstOrNew([
            'provider' => $snapshot->provider,
            'provider_id' => $snapshot->providerId,
        ]);

        $attributes = $this->attributes($owner, $snapshot);

        // Never regress a settled invoice. Stripe fires invoice.finalized (open) then invoice.payment_succeeded
        // (paid); if the finalized delivery is retried AFTER the paid one (a transient failure on the first
        // attempt), it must not overwrite the paid record back to open. A provider invoice never leaves a
        // terminal state, so once stored terminal the status is kept whatever a later open snapshot says.
        if ($record->exists && $record->status->isTerminal() && ! $snapshot->status->isTerminal()) {
            unset($attributes['status']);
        }

        $record->fill($attributes)->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $owner, InvoiceSnapshot $snapshot): array
    {
        return [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'number' => $snapshot->number,
            'total_minor' => $snapshot->totalMinor,
            'subtotal_minor' => $snapshot->subtotalMinor,
            'tax_minor' => $snapshot->taxMinor,
            'currency' => $snapshot->currency,
            'status' => $snapshot->status,
            'issued_at' => $snapshot->issuedAt !== null ? Carbon::createFromTimestamp($snapshot->issuedAt) : null,
            'reverse_charge' => $snapshot->reverseCharge,
            'buyer' => $snapshot->buyer,
            'lines' => $snapshot->lines,
        ];
    }
}
