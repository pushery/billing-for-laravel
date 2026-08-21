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
 * scoped by `currency`).
 *
 * `provider_coupon_id` is the provider's own id for this coupon, and the Stripe checkout READS it:
 * `StripeCheckout::stripeCouponFor()` prefers it over the global `billing.coupons.<code>.stripe_coupon`
 * map, after the code has passed the catalog check. It had no reader at all until 2026-08-19 — an adopter
 * who filled it, because this model and the migration offer it, got a discount that never applied and
 * nothing threw or warned. Leaving it null keeps the config answering, so a config-only installation
 * reads exactly as it did before.
 *
 * This model is the persistence surface only (the redemption ledger and the discount math live with the
 * DiscountResolver / billing engine); it carries the columns, casts and the redemptions relation.
 *
 * ## Who writes this row
 *
 * The CONSUMING APPLICATION. Nothing in this package creates a coupon -- a discount is a commercial decision
 * and its catalog is theirs. The package reads them (`CouponRedeemer` locks and spends one) and writes only
 * the redemption side. So a column here with no writer in `src/` is the correct state and not a gap; what
 * would be a defect is a READER that turns an absent value into an answer.
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

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, bool|int>
     */
    protected $attributes = [
        'redeemed_count' => 0,
        'active' => true,
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
