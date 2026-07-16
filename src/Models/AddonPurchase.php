<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recorded one-time add-on purchase, unique per checkout `reference`.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $reference
 * @property string $addon_key
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $payment_reference
 * @property int $reversed_minor
 * @property ?Carbon $revoked_at
 * @property ?string $revoked_reason
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class AddonPurchase extends Model
{
    protected $table = 'billing_addon_purchases';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'reference', 'addon_key', 'amount_minor', 'currency',
        'payment_reference', 'reversed_minor', 'revoked_at', 'revoked_reason',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'reversed_minor' => 'integer',
        'revoked_at' => 'datetime',
    ];
}
