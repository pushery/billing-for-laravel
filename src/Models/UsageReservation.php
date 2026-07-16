<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\ReservationState;

/**
 * One hold on a metered allowance. The counter's `reserved` aggregate is what the ceiling check reads;
 * this row is the hold's identity, so it can be settled exactly once and expire if it never is.
 *
 * @property string $token
 * @property string $owner_type
 * @property int $owner_id
 * @property string $meter_key
 * @property string $period
 * @property int $amount
 * @property ?int $included
 * @property ReservationState $state
 * @property Carbon $expires_at
 */
final class UsageReservation extends Model
{
    protected $table = 'billing_usage_reservations';

    /** @var list<string> */
    protected $fillable = ['token', 'owner_type', 'owner_id', 'meter_key', 'period', 'amount', 'included', 'state', 'expires_at'];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
        'state' => ReservationState::class,
        'expires_at' => 'datetime',
    ];
}
