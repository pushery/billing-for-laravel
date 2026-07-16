<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * The package's own coupon — a local discount definition the billing engine applies, independent of any
 * provider. `value` is a percentage (for `type = percent`) or an amount in minor units (for `type = fixed`,
 * scoped by `currency`). The Stripe driver may map it to a Stripe coupon via `provider_coupon_id`.
 *
 * This model is the persistence surface only (the redemption ledger and the discount math live with the
 * DiscountResolver / billing engine); it carries the columns, casts and the redemptions relation.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property ?string $currency
 * @property string $duration
 * @property ?int $duration_in_cycles
 * @property ?int $max_redemptions
 * @property int $redeemed_count
 * @property ?Carbon $expires_at
 * @property ?string $provider_coupon_id
 * @property bool $active
 */
final class Coupon extends Model
{
    protected $table = 'billing_coupons';

    /** @var list<string> */
    protected $fillable = [
        'code', 'type', 'value', 'currency', 'duration', 'duration_in_cycles',
        'max_redemptions', 'redeemed_count', 'expires_at', 'provider_coupon_id', 'active',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'value' => 'integer',
        'duration_in_cycles' => 'integer',
        'max_redemptions' => 'integer',
        'redeemed_count' => 'integer',
        // Not the plain datetime cast: this package targets a non-UTC app, and the framework default re-reads a
        // stored instant in the app timezone, shifting it by the offset on every round-trip (see InvoiceRecord).
        'expires_at' => UtcDateTime::class,
        'active' => 'boolean',
    ];

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
