<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\Models\Concerns\StoresMomentsWithTheirOffset;

/**
 * What one order took off a discount allowance, one row per allowance and order.
 *
 * @property int $id
 * @property int $discount_allowance_id
 * @property int $order_id
 * @property int $amount_minor
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class DiscountAllowanceUse extends Model
{
    use Replaceable;
    use StoresMomentsWithTheirOffset;

    protected $table = 'billing_discount_allowance_uses';

    /** @var list<string> */
    protected $fillable = ['discount_allowance_id', 'order_id', 'amount_minor'];

    /** @var array<string,string> */
    protected $casts = [
        'discount_allowance_id' => 'integer',
        'order_id' => 'integer',
        'amount_minor' => 'integer',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::model());
    }
}
