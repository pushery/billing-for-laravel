<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
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
 * @property ?int $synced_event_at
 * @property ?Carbon $current_period_start
 * @property ?Carbon $current_period_end
 */
final class Subscription extends Model
{
    protected $table = 'billing_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'provider', 'provider_id', 'status', 'tier_key',
        'scheduled_tier_key', 'scheduled_swap_at',
        'trial_ends_at', 'ends_at', 'delinquent_since', 'dunning_level', 'synced_event_at',
        'current_period_start', 'current_period_end',
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
