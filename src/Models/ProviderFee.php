<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\ValueObjects\Money;

/**
 * One charge the payment provider made to the platform.
 *
 * @property int $id
 * @property string $provider
 * @property string $reference
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property string $currency
 * @property int $amount_minor
 * @property ?ReversalCause $cause
 * @property Carbon $occurred_at
 * @property ?Carbon $merchant_erased_at
 */
class ProviderFee extends Model
{
    protected $table = 'billing_provider_fees';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'reference', 'merchant_type', 'merchant_id',
        'currency', 'amount_minor', 'cause', 'occurred_at', 'merchant_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'cause' => ReversalCause::class,
        'occurred_at' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** What was charged. */
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
