<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\ValueObjects\Invoice;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * A stored invoice. Maps to the neutral {@see Invoice} DTO the Invoices contract returns, so views
 * never touch the model or a provider object.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property ?string $provider
 * @property ?string $provider_id
 * @property ?string $number
 * @property ?int $credited_invoice_id
 * @property ?string $credited_invoice_number
 * @property int $total_minor
 * @property string $currency
 * @property InvoiceStatus $status
 * @property ?Carbon $issued_at
 * @property ?Carbon $created_at
 * @property ?array<string,mixed> $buyer
 * @property ?int $subtotal_minor
 * @property ?int $tax_minor
 * @property bool $reverse_charge
 * @property ?string $buyer_reference
 * @property ?string $vat_note
 * @property bool $oss
 * @property ?string $destination_country
 * @property ?string $oss_rate
 * @property ?array<int,array<string,mixed>> $lines
 */
final class InvoiceRecord extends Model
{
    protected $table = 'billing_invoices';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'provider_id', 'number', 'total_minor', 'currency',
        'status', 'issued_at', 'credited_invoice_id', 'credited_invoice_number', 'buyer', 'subtotal_minor',
        'tax_minor', 'reverse_charge', 'buyer_reference', 'vat_note', 'oss', 'destination_country', 'oss_rate',
        'lines',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'total_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'tax_minor' => 'integer',
        'reverse_charge' => 'boolean',
        'oss' => 'boolean',
        // decimal:2 keeps the applied VAT rate exact (a string, e.g. "19.00") — a float cast would let
        // 19.00 drift, and the rate on a frozen tax document must round-trip byte-for-byte.
        'oss_rate' => 'decimal:2',
        'status' => InvoiceStatus::class,
        // NOT the plain 'datetime' cast: this package targets a non-UTC (German) app, and the framework
        // default re-reads a stored instant in the APP timezone, shifting an invoice's frozen issue instant
        // by the UTC offset on every round-trip. UtcDateTime keeps the instant exact — same as the
        // Subscription model does for its provider timestamps.
        'issued_at' => UtcDateTime::class,
        'buyer' => 'array',
        'lines' => 'array',
    ];

    /**
     * GoBD immutability: an issued invoice's CONTENT must not change once recorded. The status (the payment
     * state — open → paid → …) may still transition, and the buyer / credited-invoice links are allowed to
     * reconcile (a credit note persisted before its original is stored later backfills the original's frozen
     * buyer and local id). But the number, the amounts, the currency, the tax treatment, the issue date and
     * the line items are frozen: any code path that dirties one of them on an EXISTING row is rejected.
     */
    #[Override]
    protected static function booted(): void
    {
        self::updating(static function (self $invoice): void {
            // Scalar frozen fields: isDirty is a reliable, engine-neutral comparison. The tax treatment of an
            // issued document is frozen just like its amounts — reverse-charge, the OSS scheme/destination/rate
            // and the routing reference all determine what an already-emitted e-invoice claims, so none may change.
            foreach ([
                'number', 'total_minor', 'subtotal_minor', 'tax_minor', 'currency', 'reverse_charge',
                'buyer_reference', 'vat_note', 'oss', 'destination_country', 'oss_rate', 'issued_at',
            ] as $field) {
                if ($invoice->isDirty($field)) {
                    throw new RuntimeException("An issued invoice is immutable; '{$field}' cannot change after it is recorded.");
                }
            }

            // Lines is a JSON column, and isDirty compares the ENCODED string: a provider engine re-serializes
            // the SAME content differently (a MySQL JSON round-trip is not byte-identical to PHP's json_encode),
            // so a faithful re-persist of the same lines would falsely trip. Compare the DECODED content with a
            // loose inequality instead — that catches a real edit while ignoring serialization noise.
            $rawOriginalLines = $invoice->getRawOriginal('lines');
            $originalLines = is_string($rawOriginalLines) ? json_decode($rawOriginalLines, true) : null;

            if ($originalLines != $invoice->lines) {
                throw new RuntimeException("An issued invoice is immutable; 'lines' cannot change after it is recorded.");
            }
        });
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /**
     * Whether this row is an invoice correction — a document that corrects another invoice, rather than a
     * charge. It is identified by a reference to what it corrects (the local row or the provider's own
     * number), which drives the accounting direction (DATEV books it "H", not "S") and the e-invoice type
     * code (381/384, not 380). A correction carries POSITIVE amounts; the correction's nature — not a sign —
     * is what inverts the meaning, exactly as EN 16931 and a DATEV Haben booking require.
     */
    public function isCorrection(): bool
    {
        return $this->credited_invoice_id !== null || $this->credited_invoice_number !== null;
    }

    public function toDto(?string $downloadUrl = null): Invoice
    {
        return new Invoice(
            id: (string) $this->id,
            date: $this->issued_at ?? $this->created_at ?? Carbon::now(),
            total: $this->total(),
            status: $this->status,
            number: $this->number,
            downloadUrl: $downloadUrl,
        );
    }
}
