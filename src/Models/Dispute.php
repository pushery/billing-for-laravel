<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\ValueObjects\Money;

/**
 * One dispute a provider opened over a payment.
 *
 * @property int $id
 * @property string $provider
 * @property string $dispute_reference
 * @property string $payment_reference
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property ?string $account_reference
 * @property string $currency
 * @property int $amount_minor
 * @property DisputeReason $reason
 * @property ?string $reason_code
 * @property ?Carbon $evidence_due_by
 * @property Carbon $opened_at
 * @property ?Carbon $merchant_erased_at
 */
class Dispute extends Model
{
    use Replaceable;

    protected $table = 'billing_disputes';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'dispute_reference', 'payment_reference', 'merchant_type', 'merchant_id', 'account_reference',
        'currency', 'amount_minor', 'reason', 'reason_code', 'evidence_due_by', 'opened_at', 'merchant_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'reason' => DisputeReason::class,
        'evidence_due_by' => UtcDateTime::class,
        'opened_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** The disputed amount. */
    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency);
    }

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }
}
