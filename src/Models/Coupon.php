<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Contracts\ArrearsClock;
use Pushery\Billing\Discounts\CouponCodes;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The package's own coupon — a local discount definition the billing engine applies, independent of any
 * provider. `value` is a percentage (for `type = percent`) or an amount in minor units (for `type = fixed`,
 * scoped by `currency`).
 *
 * `provider_coupon_id` is the provider's own id for this coupon, and the Stripe checkout READS it:
 * `StripeCheckout::providerCouponFor()` prefers it over the global `billing.coupons.<code>.stripe_coupon`
 * map, after the code has passed the catalog check. Leaving it null keeps the config answering, so a
 * config-only installation reads its discounts from the config alone.
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
 * ## Who a coupon belongs to
 *
 * `merchant_uid` is the issuer: the platform (`platform`) or one merchant (`m:<type>#<id>`). It exists
 * so `code` is unique per issuer, not globally: with one shared namespace two sellers could not both run a
 * `SUMMER25`, and a name would be reserved for whoever claimed it first. On a marketplace the seller's own
 * discount code is the acquisition tool, so that would not be a limitation of the feature, it would be its
 * absence.
 *
 * Always read a coupon through {@see Coupon::scopeIssuedBy()}, and by its code through {@see CouponCodes::find()},
 * which matches a code the same way on every database. A bare `where('code', ...)` finds ANY issuer's coupon of
 * that name, which after this column exists means one seller's discount can be spent on another seller's sale.
 * `CouponsAreReadScopedTest` holds that, because the mistake is a query that looks completely ordinary.
 *
 * @property int $id
 * @property string $code
 * @property string $merchant_uid
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
class Coupon extends Model
{
    use Replaceable;

    protected $table = 'billing_coupons';

    /** @var list<string> */
    protected $fillable = [
        'code', 'merchant_uid', 'type', 'value', 'currency', 'duration', 'duration_in_cycles',
        'max_redemptions', 'redeemed_count', 'expires_at', 'provider_coupon_id', 'active',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'redeemed_count' => 0,
        'active' => true,
        // The platform sentinel, matching the schema default. A coupon created without an issuer belongs to
        // the platform, which is what every coupon that existed before this column meant.
        'merchant_uid' => 'platform',
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
        return $this->hasMany(CouponRedemption::model(), 'coupon_id');
    }

    /**
     * Only the coupons this issuer put out.
     *
     * The scope is part of the QUESTION rather than a filter applied to the answer — the same shape
     * {@see ArrearsClock} uses, and for the same reason. A lookup that finds a
     * coupon first and checks its issuer afterwards has already read somebody else's row, and the check is
     * the step that gets dropped.
     *
     * A null scope means the PLATFORM, which in a single-seller install is the only issuer there is. That
     * makes it the same query the package ran before this column existed.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIssuedBy(Builder $query, ?MerchantScope $merchant = null): Builder
    {
        return $query->where('merchant_uid', ($merchant ?? MerchantScope::platform())->uid());
    }

    /**
     * Whether this coupon can still be honored: switched on, and not past its expiry.
     *
     * ONE definition for every reader that answers yes or no: the resolver, the hosted checkout's provider mapping
     * and the local driver's coupon question. A copy in each would drift, and a copy that left it out would let a
     * withdrawn coupon's Stripe discount reach the invoice wherever the config accepts the same code.
     * `CouponRedeemer` keeps its own checks, because it has to say WHICH condition failed.
     *
     * The redemption cap is not part of it. That is a race by nature, and the only place it can be enforced
     * truthfully is the redeemer's locked transaction.
     */
    public function isLive(): bool
    {
        return $this->active && (! $this->expires_at instanceof Carbon || ! $this->expires_at->isPast());
    }

    /**
     * Whether this row describes a discount at all: a `percent` from 1 to 100, or a `fixed` amount above zero.
     *
     * The row is written by the application, so a percentage of 0 or 150 or a type of `percentage` is a row the
     * package meets. ONE definition for the local driver's coupon question, the redemption of a carried code and
     * the cycle that applies it: a screen that read such a row as a code that took, while the cycle read it as no
     * discount, would show a customer a discount and charge them in full.
     */
    public function describesADiscount(): bool
    {
        return match ($this->type) {
            'percent' => $this->value >= 1 && $this->value <= 100,
            'fixed' => $this->value > 0,
            default => false,
        };
    }
}
