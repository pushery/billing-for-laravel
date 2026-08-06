<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\SubscriptionSnapshot;

/**
 * The local subscription-state row. Its predicates mirror the provider vocabulary (a grace-period
 * subscription is also "active" — the presenter resolves the overlap by precedence). Column-
 * authoritative: dates drive trial/grace, never a live provider call.
 *
 * @property string $owner_type
 * @property int $owner_id
 * @property string $type
 * @property string $provider
 * @property ?string $provider_id
 * @property string $status
 * @property ?string $tier_key
 * @property ?string $scheduled_tier_key
 * @property ?Carbon $scheduled_swap_at
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $ends_at
 * @property ?Carbon $delinquent_since
 * @property int $dunning_level
 * @property ?Carbon $payment_reminded_on
 * @property ?Carbon $terminated_at
 * @property ?int $synced_event_at
 * @property ?Carbon $current_period_start
 * @property ?Carbon $current_period_end
 * @property string $merchant_uid
 * @property ?string $merchant_type
 * @property int|string|null $merchant_id
 */
final class Subscription extends Model
{
    protected $table = 'billing_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'provider', 'provider_id', 'status', 'tier_key',
        'scheduled_tier_key', 'scheduled_swap_at',
        'trial_ends_at', 'ends_at', 'delinquent_since', 'dunning_level', 'payment_reminded_on', 'synced_event_at',
        'current_period_start', 'current_period_end',
        'merchant_uid', 'merchant_type', 'merchant_id',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'type' => 'default',
        'dunning_level' => 0,
        // The single-seller sentinel. A subscription created without a merchant reads as the platform's
        // own, exactly as the schema default records it — so the row and the freshly-built instance agree
        // before anyone re-reads. Held against the migration by ModelSchemaDefaultsTest.
        'merchant_uid' => 'platform',
    ];

    /** @var array<string,string> */
    protected $casts = [
        // Provider instants, kept in UTC on both read and write (see UtcDateTime): the default `datetime`
        // cast reads back in app.timezone, which shifts a UTC boundary by the offset and buckets usage into
        // the wrong cycle or expires a trial early on any non-UTC app.
        'trial_ends_at' => UtcDateTime::class,
        'ends_at' => UtcDateTime::class,
        'delinquent_since' => UtcDateTime::class,
        'dunning_level' => 'integer',
        // A DATE, not a datetime: the marker answers "was a reminder sent today", and a time of day would
        // make that comparison depend on when the sweep happens to run.
        'payment_reminded_on' => 'date',
        'terminated_at' => UtcDateTime::class,
        'synced_event_at' => 'integer',
        'current_period_start' => UtcDateTime::class,
        'current_period_end' => UtcDateTime::class,
        'scheduled_swap_at' => UtcDateTime::class,
    ];

    public function onTrial(): bool
    {
        // Date-driven for the local-engine drivers; status-driven for a webhook-synced Stripe row,
        // which carries the canonical status but no period dates.
        if ($this->trial_ends_at?->isFuture() === true) {
            return true;
        }

        return $this->status === 'trialing';
    }

    /** Canceled but still paid through the period end. */
    public function onGracePeriod(): bool
    {
        if ($this->ends_at?->isFuture() === true) {
            return true;
        }

        return $this->status === 'grace';
    }

    public function pastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function incomplete(): bool
    {
        return $this->status === 'incomplete';
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether this subscription expired for good and cannot be revived.
     *
     * Distinct from `onGracePeriod()` and from a status of `ended`: those describe where the row stands
     * today, this records that a decision was taken. A payment arriving after it is a NEW subscription, not
     * a resumption of this one — so the marker has to survive every status a provider may report afterwards.
     */
    public function terminated(): bool
    {
        return $this->terminated_at !== null;
    }

    /** Billing is paused: no invoice is being raised, so no paid tier is being paid for. */
    public function paused(): bool
    {
        return $this->status === 'paused';
    }

    /** Whether a plan change is scheduled but not yet in effect (a downgrade waiting for the period end). */
    public function hasScheduledSwap(): bool
    {
        return $this->scheduled_tier_key !== null;
    }

    /**
     * Record a plan change to take effect later, replacing any earlier pending one.
     *
     * Overwriting rather than stacking is deliberate: two pending downgrades make no sense — the customer's
     * latest choice is the one that should land — and keeping only the newest means the effective date is
     * always the one the customer last saw on the screen.
     */
    public function scheduleSwap(string $tierKey, CarbonInterface $at): void
    {
        $this->update(['scheduled_tier_key' => $tierKey, 'scheduled_swap_at' => $at]);
    }

    /** Drop a pending plan change — the customer canceled it, or a new upgrade took effect immediately. */
    public function cancelScheduledSwap(): void
    {
        // Cleared when EITHER column is set, not only when the tier is: a malformed row (a date with no
        // tier) must also be clearable, or the runner would re-select it by date on every pass and never
        // shake it loose. A row with both already null needs no write.
        if ($this->scheduled_tier_key === null && $this->scheduled_swap_at === null) {
            return;
        }

        $this->update(['scheduled_tier_key' => null, 'scheduled_swap_at' => null]);
    }

    /**
     * The provider-neutral lines this subscription bills each cycle.
     *
     * Empty for a Stripe subscription, whose lines live in Cashier's own `subscription_items` — this
     * relation is what the local engine uses in place of a provider-side line model.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'billing_subscription_id');
    }

    /**
     * The creator this subscription is to, or null on a single-seller (platform) row.
     *
     * @return MorphTo<Model, $this>
     */
    public function merchant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Narrow a query to one merchant's rows — the platform's own when no merchant is given.
     *
     * The scope keys on the sentinel string, never on the nullable morph, so a null scope selects exactly
     * the single-seller rows (`merchant_uid = 'platform'`) and can never pick up a merchant-scoped row. It
     * is the one place the (billable, merchant) selection is spelled, so every caller — resolver, actions,
     * state reader — narrows the same way.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForMerchant(Builder $query, ?MerchantScope $merchant): void
    {
        $query->where('merchant_uid', ($merchant ?? MerchantScope::platform())->uid());
    }

    /**
     * Narrow a query to rows that belong to a MERCHANT rather than to the platform itself.
     *
     * The inverse of the sentinel, and it exists so that a marketplace-only mechanism can say so in one
     * clause instead of reading the marketplace flag. The two are not the same test: the flag says whether
     * the surface is switched on, this says whether THIS ROW came through it. A single-seller install has no
     * non-platform row at all, so a sweep narrowed this way is byte-identical there whatever the flag does —
     * and an install that later switches the flag on does not retroactively pull its own platform
     * subscriptions into a marketplace rule.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMerchantScoped(Builder $query): void
    {
        $query->where('merchant_uid', '!=', MerchantScope::platform()->uid());
    }

    /** Build the driver-neutral snapshot the SubscriptionPresenter collapses into one state. */
    public function toSnapshot(): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot(
            subscribed: $this->active() || $this->onTrial() || $this->onGracePeriod(),
            hasSubscription: true,
            incompleteExpired: $this->status === 'incomplete_expired',
            incomplete: $this->incomplete(),
            pastDue: $this->pastDue(),
            onGracePeriod: $this->onGracePeriod(),
            onTrial: $this->onTrial(),
            active: $this->active(),
            paused: $this->paused(),
        );
    }
}
