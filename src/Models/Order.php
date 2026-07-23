<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\ValueObjects\Money;

/**
 * A local order: the billing unit a driver without a provider-side order model assembles for a due cycle,
 * processes, and produces an invoice from.
 *
 * The total is authoritative on the row (summed from the items when the order is assembled), so reading it
 * never touches the item rows or a provider. Dates are UTC instants, like every other provider timestamp in
 * the schema.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $provider
 * @property ?int $subscription_id
 * @property int $total_minor
 * @property string $currency
 * @property OrderStatus $status
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property ?Carbon $processed_at
 * @property ?string $payment_reference
 */
final class Order extends Model
{
    protected $table = 'billing_orders';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'subscription_id', 'total_minor', 'currency',
        'status', 'period_start', 'period_end', 'processed_at', 'payment_reference',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'subscription_id' => 'integer',
        'total_minor' => 'integer',
        'status' => OrderStatus::class,
        'period_start' => UtcDateTime::class,
        'period_end' => UtcDateTime::class,
        'processed_at' => UtcDateTime::class,
    ];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /** The order total as Money. */
    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }
}
