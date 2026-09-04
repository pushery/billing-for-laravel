<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;

/**
 * One redemption of a coupon by an owner — the ledger row behind a coupon's max-redemptions and per-owner
 * limits. The unique (coupon, owner) index on the table makes a second redemption of the same coupon by the
 * same owner impossible, so a double-apply cannot grant the discount twice.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property int $coupon_id
 * @property ?int $subscription_id
 * @property int $applied_count
 * @property ?string $last_applied_period
 * @property ?Carbon $redeemed_at
 */
final class CouponRedemption extends Model
{
    protected $table = 'billing_coupon_redemptions';

    /** @var list<string> */
    protected $fillable = ['owner_type', 'owner_id', 'coupon_id', 'subscription_id', 'applied_count', 'last_applied_period', 'redeemed_at'];

    /**
     * The same default the schema carries, so a freshly created model reads like one read back.
     *
     * Without it a redemption created without the column holds null while the row holds 0 — a
     * disagreement that lasts only until somebody re-reads, which is exactly why it hides.
     *
     * @var array<string, int>
     */
    protected $attributes = ['applied_count' => 0];

    /** @var array<string,string> */
    protected $casts = [
        'coupon_id' => 'integer',
        'subscription_id' => 'integer',
        'applied_count' => 'integer',
        'redeemed_at' => UtcDateTime::class,
    ];

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
