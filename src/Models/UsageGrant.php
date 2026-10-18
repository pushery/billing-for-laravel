<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * Units of a meter an owner was given until a moment, such as the allowance a plan grants for its period.
 *
 * Unlike the units an owner bought, which live in {@see PrepaidUnits} and never expire, a grant runs out: usage
 * draws on it before the bought units, the grant that expires first first, and once `expires_at` has passed an
 * expiry writes off what is left into `expired_units`. `units` is what was given, `remaining` what can still be
 * spent; a grant that paid off an overdraft first starts with less remaining than it was given.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $meter_key
 * @property int $units
 * @property int $remaining
 * @property ?string $reference
 * @property Carbon $granted_at
 * @property Carbon $expires_at
 * @property ?Carbon $expired_at
 * @property ?int $expired_units
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class UsageGrant extends Model
{
    use Replaceable;

    protected $table = 'billing_usage_grants';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'meter_key', 'units', 'remaining', 'reference',
        'granted_at', 'expires_at', 'expired_at', 'expired_units',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'units' => 'integer',
        'remaining' => 'integer',
        'expired_units' => 'integer',
        'granted_at' => UtcDateTime::class,
        'expires_at' => UtcDateTime::class,
        'expired_at' => UtcDateTime::class,
    ];
}
