<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's PREPAID balance for one meter — units they bought outright ("+1000 emails").
 *
 * Persistent and cycle-independent: unlike the tier's `included` allowance (which lives in the
 * period-scoped usage counter and expires with the cycle), prepaid units roll forever. Paid is paid.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $meter_key
 * @property int $balance
 * @property int $granted_total
 */
final class PrepaidUnits extends Model
{
    protected $table = 'billing_prepaid_units';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'meter_key', 'balance', 'granted_total'];

    /** @var array<string, string> */
    protected $casts = ['balance' => 'integer', 'granted_total' => 'integer'];
}
