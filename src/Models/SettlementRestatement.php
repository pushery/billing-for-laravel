<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\SettlementRestatementBlock;
use Pushery\Billing\Enums\SettlementRestatementState;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * One settlement that has to be issued again because the creator's standing was corrected.
 *
 * The row is the order and its record at once: it says which settlement was queued, what became of it, and
 * which two documents replaced it. The documents themselves say the rest — the cancellation names the
 * settlement it cancels, and the replacement carries the same supply date.
 *
 * @property int $id
 * @property int $original_invoice_id
 * @property Carbon $queued_for
 * @property SettlementRestatementState $state
 * @property ?SettlementRestatementBlock $blocked_reason
 * @property ?int $cancellation_invoice_id
 * @property ?int $replacement_invoice_id
 * @property ?Carbon $processed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SettlementRestatement extends Model
{
    use Replaceable;

    protected $table = 'billing_settlement_restatements';

    /** @var list<string> */
    protected $fillable = [
        'original_invoice_id', 'queued_for', 'state', 'blocked_reason', 'cancellation_invoice_id',
        'replacement_invoice_id', 'processed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'original_invoice_id' => 'integer',
        'queued_for' => UtcDateTime::class,
        'state' => SettlementRestatementState::class,
        'blocked_reason' => SettlementRestatementBlock::class,
        'cancellation_invoice_id' => 'integer',
        'replacement_invoice_id' => 'integer',
        'processed_at' => UtcDateTime::class,
    ];

    /** @return BelongsTo<InvoiceRecord, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(InvoiceRecord::model(), 'original_invoice_id');
    }
}
