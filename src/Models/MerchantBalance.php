<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * What one merchant owes the platform in one currency.
 *
 * Signed: negative is a debt the platform is owed, positive is nothing this package pays out on its own.
 * The sign is the whole state — there is no separate "in debt" flag to fall out of step with the number.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property ?int $merchant_id
 * @property string $currency
 * @property int $balance_minor
 * @property ?Carbon $in_debt_since
 * @property ?Carbon $merchant_erased_at
 */
class MerchantBalance extends Model
{
    protected $table = 'billing_merchant_balances';

    /** @var list<string> */
    protected $fillable = ['merchant_type', 'merchant_id', 'currency', 'balance_minor', 'in_debt_since', 'merchant_erased_at'];

    /** @var array<string, int> */
    protected $attributes = ['balance_minor' => 0];

    /** @var array<string,string> */
    protected $casts = [
        'balance_minor' => 'integer',
        'in_debt_since' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];

    /** @return MorphTo<Model, $this> */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }
}
