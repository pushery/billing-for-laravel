<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\ReviewState;

/**
 * A creator's own invoice, submitted through the fallback lane and awaiting (or having passed) reconciliation.
 *
 * The amounts are what the document states; the review decides whether they match what the creator earned and
 * may therefore be paid. The findings hold the per-field result so a creator sees what is missing.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $issuer_invoice_number
 * @property int $net_minor
 * @property int $tax_minor
 * @property string $currency
 * @property ?string $source
 * @property Carbon $received_at
 * @property ReviewState $review_state
 * @property ?array<string, mixed> $findings
 */
class SubmittedInvoice extends Model
{
    protected $table = 'billing_submitted_invoices';

    protected $fillable = [
        'owner_type', 'owner_id', 'issuer_invoice_number', 'net_minor', 'tax_minor',
        'currency', 'source', 'received_at', 'review_state', 'findings',
    ];

    protected $attributes = [
        'review_state' => 'pending',
    ];

    protected $casts = [
        'net_minor' => 'integer',
        'tax_minor' => 'integer',
        'received_at' => UtcDateTime::class,
        'review_state' => ReviewState::class,
        'findings' => 'array',
    ];

    /** Whether this submission clears its creator for payout. */
    public function releasesPayout(): bool
    {
        return $this->review_state->releasesPayout();
    }
}
