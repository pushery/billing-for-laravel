<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * An owner's cap on what usage past the included allowance may cost per billing period.
 *
 * `amount_minor` is in minor units of `currency`. A row without them stands for the configured default: the row
 * exists so that every metered request of the owner takes turns on it, whether or not the owner ever set a cap.
 * `reached_period` is the period in which the cap last stopped the owner.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property string|null $reached_period
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SpendCap extends Model
{
    use Replaceable;

    protected $table = 'billing_spend_caps';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'amount_minor', 'currency', 'reached_period'];

    /** @var array<string, string> */
    protected $casts = ['amount_minor' => 'integer'];
}
