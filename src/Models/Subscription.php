<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
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
