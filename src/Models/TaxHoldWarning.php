<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * A record that one merchant was told the tax-standing deadline is coming.
 *
 * @property int $id
 * @property string $merchant_type
 * @property int $merchant_id
 * @property Carbon $deadline
 * @property Carbon $warned_at
 */
final class TaxHoldWarning extends Model
{
    protected $table = 'billing_tax_hold_warnings';

    /** @var list<string> */
    protected $fillable = ['merchant_type', 'merchant_id', 'deadline', 'warned_at'];

    /** @var array<string,string> */
    protected $casts = [
        'deadline' => 'date',
        'warned_at' => UtcDateTime::class,
    ];
}
