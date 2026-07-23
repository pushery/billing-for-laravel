<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\ValueObjects\Money;

/**
 * One line of a local order. The order's total is the sum of its lines, so a line's total can be negative
 * (a discount, a credit-balance offset) as well as positive (a charge).
 *
 * The tax rate is stored in basis points, not a percentage float: the money layer never constructs a float,
 * and neither does the rate that applies to it (see Money's bps primitives). Null when the line carries no
 * tax at all — a discount or a credit line.
 *
 * @property int $order_id
 * @property string $description
 * @property int $unit_price_minor
 * @property int $quantity
 * @property int $total_minor
 * @property string $currency
 * @property ?int $tax_bps
 * @property OrderItemType $type
 * @property ?array<array-key, mixed> $metadata
 */
final class OrderItem extends Model
{
    protected $table = 'billing_order_items';

    /** @var list<string> */
    protected $fillable = [
        'order_id', 'description', 'unit_price_minor', 'quantity', 'total_minor', 'currency', 'tax_bps', 'type', 'metadata',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'order_id' => 'integer',
        'unit_price_minor' => 'integer',
        'quantity' => 'integer',
        'total_minor' => 'integer',
        'tax_bps' => 'integer',
        'type' => OrderItemType::class,
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** The unit price as Money. */
    public function unitPrice(): Money
    {
        return Money::of($this->unit_price_minor, $this->currency);
    }

    /** The line total as Money. */
    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }
}
