<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Concerns\Replaceable;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\SubscriptionSnapshot;

/**
 * The local subscription-state row. Its predicates mirror the provider vocabulary (a grace-period
 * subscription is also "active" — the presenter resolves the overlap by precedence). Column-
 * authoritative: dates drive trial/grace, never a live provider call.
 *
 * @property int $id
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
 * @property ?Carbon $scheduled_processing_at
 * @property string $merchant_uid
 * @property ?string $merchant_type
 * @property int|string|null $merchant_id
 * @property ?string $declaration_reference
 * @property ?Carbon $started_at
 * @property ?int $seat_quantity
 * @property ?Carbon $seat_quantity_since
 * @property int $seat_days_accrued
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Subscription extends Model
{
    use Replaceable;

    protected $table = 'billing_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'provider', 'provider_id', 'status', 'tier_key',
        'scheduled_tier_key', 'scheduled_swap_at',
        'trial_ends_at', 'ends_at', 'delinquent_since', 'dunning_level', 'payment_reminded_on', 'synced_event_at',
        'current_period_start', 'current_period_end', 'scheduled_processing_at',
        'merchant_uid', 'merchant_type', 'merchant_id', 'declaration_reference', 'started_at',
        'seat_quantity', 'seat_quantity_since', 'seat_days_accrued',
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
        'type' => self::TYPE_DEFAULT,
        'dunning_level' => 0,
        // The single-seller sentinel. A subscription created without a merchant reads as the platform's
        // own, exactly as the schema default records it — so the row and the freshly-built instance agree
        // before anyone re-reads. Held against the migration by ModelSchemaDefaultsTest.
        'merchant_uid' => 'platform',
        'seat_days_accrued' => 0,
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
        'scheduled_processing_at' => UtcDateTime::class,
        'scheduled_swap_at' => UtcDateTime::class,
        'started_at' => UtcDateTime::class,
        'seat_quantity' => 'integer',
        'seat_quantity_since' => UtcDateTime::class,
        'seat_days_accrued' => 'integer',
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

    /**
     * The moment the subscription was canceled to, when that moment lies before the end of its period.
     *
     * An ordinary cancellation ends at the period end; this one ends earlier, and the rest of the period is
     * what a prorated cancellation pays back. So it is final: resuming it would hand back time the owner was
     * refunded for, and canceling it again at the period end would move the end past the refund. Both
     * refuse or leave it alone, and the account screen reads this to offer neither.
     *
     * Read from the two columns, never from a provider call. A row that does not know its period end cannot
     * say, and answers null, as does a row that ends at its period end or has no end at all.
     */
    public function endInsideItsPeriod(): ?Carbon
    {
        if ($this->ends_at === null || $this->current_period_end === null) {
            return null;
        }

        return $this->ends_at->lessThan($this->current_period_end) ? $this->ends_at : null;
    }

    /**
     * Whether the period in progress is the subscription's last: it was canceled to a moment at or before the
     * period's end.
     *
     * A driver that collects a period at its end reads this when it does: the cycle then closes the
     * subscription instead of opening the next period.
     */
    public function endsWithThisPeriod(): bool
    {
        return $this->ends_at !== null
            && $this->current_period_end !== null
            && $this->ends_at->lessThanOrEqualTo($this->current_period_end);
    }

    /**
     * Refuse a cancellation to a moment this subscription cannot end at, naming the method that does it instead.
     *
     * A moment that has passed is `cancelNow()`, and ending at it would date the end before the request was
     * made. A moment after the period end is `cancel()`: a driver that bills a period at its end cannot bill
     * part of the next one. The period end itself is allowed, and is the same end `cancel()` sets. A row
     * that does not know its period end is refused only for the past.
     *
     * @throws InvalidArgumentException
     */
    public function assertCanEndAt(CarbonInterface $endsAt): void
    {
        if (! $endsAt->isFuture()) {
            throw new InvalidArgumentException(
                "Cannot cancel to {$endsAt->toIso8601String()}: that moment has passed. End the subscription now with cancelNow()."
            );
        }

        if ($this->current_period_end !== null && $endsAt->greaterThan($this->current_period_end)) {
            throw new InvalidArgumentException(
                "Cannot cancel to {$endsAt->toIso8601String()}: the period in progress ends at "
                ."{$this->current_period_end->toIso8601String()}. Cancel at the period end with cancel()."
            );
        }
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
        return $this->hasMany(SubscriptionItem::model(), 'billing_subscription_id');
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
     * Whoever holds this subscription.
     *
     * Five other models in this package already declare the same relation over the same two columns; this
     * one did not, so anything that needed the person behind a subscription resolved `owner_type` and
     * `owner_id` by hand. That is one hand-rolled morph lookup per caller, and under
     * `preventLazyLoading` it is also the shape that turns a sweep into an N+1 the moment somebody adds a
     * read inside the loop.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The subscription type an owner has when they have exactly one.
     *
     * A constant because the string had thirteen readers and two writers, and a literal with fifteen sites
     * is a literal that gets typed slightly wrong once.
     */
    public const string TYPE_DEFAULT = 'default';

    /**
     * Narrow a query to one owner's rows.
     *
     * The other half of the selection {@see self::scopeForMerchant()} already spells once, and it was
     * written out by hand at every call site that uses that one. The cost was paid in commit 96f982e2:
     * a single rule arrived as six identical one-liners in six files, and the reason it was six of the
     * thirteen sites rather than all thirteen lived only in the commit message.
     *
     * Returns a builder rather than a row, deliberately. The call sites differ in ways that matter — one
     * takes every merchant, one locks for update, one asks for all rows rather than the newest — and a
     * helper that returned a subscription would have flattened those differences into a marketplace defect.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForOwner(Builder $query, Model $owner): void
    {
        $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }

    /**
     * Narrow a query to the DEFAULT subscription type.
     *
     * Separate from {@see self::scopeForOwner()} rather than combined with it, because the two conditions
     * are asked separately as well: there are owner queries that do not care about the type. Combining them
     * would be shorter here and would tie them together for good.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfDefaultType(Builder $query): void
    {
        $query->ofType(null);
    }

    /**
     * Narrow a query to ONE contract type — the default one when none is named.
     *
     * The uniqueness of a row is (owner, type, merchant), and `ofDefaultType()` answers only about the
     * first of those contracts. A second one with the same merchant — a sponsorship beside a subscription,
     * measured in a consumer — is a different row that the default scope cannot see, and a reader that
     * cannot see it does not report a gap: it reports the other contract's row as if it were the one asked
     * for. So the default is expressed THROUGH this scope rather than beside it, and the thirteen callers
     * of `ofDefaultType()` keep their exact meaning while gaining a way to say which one they mean.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfType(Builder $query, ?string $type = null): void
    {
        $query->where('type', $type ?? self::TYPE_DEFAULT);
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

    /**
     * The rows a local engine should act on now: scheduled at or before the moment, in a state that may be
     * charged.
     *
     * The state filter is the only thing between a canceled customer and a fresh debit, because a local
     * engine charges on its own initiative rather than being told to by a provider. So the list is stated
     * as what IS charged rather than as what is not — a new state added tomorrow is then excluded until
     * somebody decides otherwise, which is the safe direction for a query that moves money.
     *
     * Three states are in, and one of them is worth saying out loud. `Grace` is a subscription the customer
     * has canceled but paid through: a cycle falling due inside it is the renewal they have not declined,
     * and skipping it would give away the period they bought. `PastDue` is in because that is where a
     * retry lives — the dunning ladder moves the schedule, and excluding the state would mean the ladder
     * could never fire a second attempt.
     *
     * A NULL schedule is "not scheduled", never "overdue". The other reading would charge every row in the
     * table on the first run after the column was added.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDueForProcessing(Builder $query, ?DateTimeInterface $now = null): void
    {
        $moment = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();

        $query->whereNotNull('scheduled_processing_at')
            ->where('scheduled_processing_at', '<=', $moment->utc())
            ->whereIn('status', [
                SubscriptionState::Active->value,
                SubscriptionState::Grace->value,
                SubscriptionState::PastDue->value,
                // TRIALING BELONGS HERE, and it was missing for as long as nothing in this package created
                // a local trialing subscription. Under a provider that drives its own cycle the trial ends
                // at the provider and arrives as an event, so the sweep never had to see one.
                //
                // A local-engine trial is scheduled at its own END, so it is due exactly once: at the
                // moment the free period stops. Without it on this list the row was invisible to the run
                // that collects — no charge, no state change, no log line, and a customer keeping their
                // access indefinitely for nothing. The date is what keeps a RUNNING trial untouched, and
                // it is the same mechanism that keeps every other state from being collected early.
                SubscriptionState::Trialing->value,
            ]);
    }

    /**
     * Whether this owner has ever been granted a subscription trial.
     *
     * One reader for a fact that three places need and that must not be answered differently by any of
     * them: the starter decides whether to grant one, the plan screen decides whether to ADVERTISE one,
     * and the mandate effect must not erase the evidence when it reuses the row. A screen that promised
     * what the starter refuses is the same defect as a starter that granted what the screen did not offer
     * — only the customer notices it later, after pressing a button that said "includes a free trial".
     *
     * The evidence is `trial_ends_at` on the owner's single row for this type and scope. It outlives the
     * trial itself: the status moves on, the period is advanced, a returning customer reuses the row, and
     * the column keeps saying that a trial was granted once. A separate counter would be a second version
     * of one fact, and it would drift the first time anything else wrote the row.
     *
     * Deliberately NOT the owner's own `trial_ends_at`, which the GENERIC trial owns and `onGenericTrial()`
     * reads as "a tier is unlocked without a subscription".
     */
    public static function ownerHasHadATrial(Model $owner): bool
    {
        return self::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant(null)
            ->whereNotNull('trial_ends_at')
            ->exists();
    }

    /**
     * Whether a NEW subscription may take this row's place.
     *
     * One question, one answer, read from two sides that must never disagree: `LocalSubscriptionStarter`
     * asks it before sending a customer to the provider, and `StartSubscriptionOnMandate` asks it again
     * when the payment finally settles — which can be days later, so the answer really can have changed in
     * between. Two copies of the list would let the starter permit what the effect then refuses, or worse,
     * let the effect overwrite something the starter would have protected.
     *
     * `ended` and `incomplete_expired` are the two terminal states. Refusing on them would leave a customer
     * who once canceled, or whose first payment lapsed, permanently unable to come back — the direction a
     * guard must never create. Everything else is a subscription somebody is living with right now,
     * including `past_due`, whose arrears belong to it.
     */
    public function isReplaceableByANewSubscription(): bool
    {
        return in_array(
            $this->status,
            [SubscriptionState::Ended->value, SubscriptionState::IncompleteExpired->value],
            true,
        );
    }

    /**
     * Move the cycle on by one interval, computed locally.
     *
     * Everything a provider-driven driver is told, a local one works out: the period the customer is
     * paying for next, and when to try to collect it. The month-end anchor is the part that has to be got
     * right rather than approximated — see {@see BillingInterval::advance()}.
     *
     * The new period starts where the old one ended, so cycles abut exactly and no moment of usage falls
     * between two of them. The next run is scheduled at the new period's end, which is the plain case; a
     * dunning retry moves the schedule alone and deliberately leaves the period where it is.
     */
    public function advanceCycle(BillingInterval $interval): void
    {
        $start = $this->current_period_end ?? Carbon::now()->utc();
        $end = $interval->advance($start);

        $this->update([
            'current_period_start' => $start,
            'current_period_end' => $end,
            'scheduled_processing_at' => $end,
            // The seats in force carry into the new period, which has billed none of them yet.
            'seat_quantity_since' => $start,
            'seat_days_accrued' => 0,
        ]);
    }

    /**
     * The seat-days this period has run up to the given moment, counted in whole days.
     *
     * The seat-days before the last change are held in `seat_days_accrued`; the current quantity adds one per
     * whole day it has held, from `seat_quantity_since` or the start of the period, whichever is later. A
     * subscription with no seat quantity counts as one seat, which is how it bills without seats.
     */
    public function seatDaysUntil(CarbonInterface $moment): int
    {
        $start = $this->current_period_start;

        if (! $start instanceof CarbonInterface) {
            return 0;
        }

        $since = $start;

        if ($this->seat_quantity_since instanceof CarbonInterface && $this->seat_quantity_since->greaterThan($start)) {
            $since = $this->seat_quantity_since;
        }

        $days = $moment->greaterThan($since) ? (int) floor($since->diffInDays($moment)) : 0;

        return $this->seat_days_accrued + ($this->seat_quantity ?? 1) * $days;
    }

    /**
     * Change the seats this subscription pays for, from the given moment on.
     *
     * The days the old quantity held until then are added to the period's seat-days, so the cycle that closes
     * the period bills each quantity for the days it held.
     */
    public function changeSeatQuantity(int $quantity, CarbonInterface $from): void
    {
        $this->update([
            'seat_days_accrued' => $this->seatDaysUntil($from),
            'seat_quantity' => $quantity,
            'seat_quantity_since' => $from,
        ]);
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
