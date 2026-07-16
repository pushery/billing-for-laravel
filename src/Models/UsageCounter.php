<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An owner's usage of one meter in one period.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $meter_key
 * @property string $period
 * @property int $used
 * @property int $reserved
 * @property int $prepaid_used
 * @property ?Carbon $warned_at when the owner was warned this meter is running out, for this period
 */
final class UsageCounter extends Model
{
    protected $table = 'billing_usage_counters';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'meter_key', 'period', 'used', 'reserved', 'prepaid_used', 'warned_at'];

    /** @var array<string,string> */
    protected $casts = ['used' => 'integer', 'reserved' => 'integer', 'prepaid_used' => 'integer', 'warned_at' => 'datetime'];
}
