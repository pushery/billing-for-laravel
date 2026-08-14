<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\MerchantScopedCustomerDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\AccountBillingUpdated;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Mirrors a provider subscription change onto the owner's denormalized tier column — the hot-path
 * read every entitlement check keys on — and records the change on the local subscription-state row
 * that the in-app subscription actions act on. Hard dunning: only a state that actually grants access
 * sets the paid tier; a past-due/incomplete/ended subscription pulls the tier to zero rather than
 * leaving a stale paid value. An access-granting subscription whose price maps to no configured tier
 * falls back to the last tier resolved on the local row, and only leaves the column alone when no tier
 * was ever known — unknown is not zero, and the owner is paying. Admin-comped tiers (config
 * `untouchable_tiers`) are never overwritten by the provider.
 *
 * Ordering-safe: the read and the writes commit in one transaction under a row lock, and an out-of-
 * order or retried OLDER event (by the provider event timestamp) is ignored rather than regressing a
 * newer state. A redelivery of the latest event is idempotent — it converges on the same values. Two
 * concurrent FIRST deliveries can both read no row (a row that does not exist cannot be locked) and both
 * insert; `insertOrIgnore` makes the loser's insert a NO-OP rather than a unique violation, and it then
 * re-reads under the lock and orders its own event against whichever row won. Nothing reruns and nothing
 * is dropped — the loser finishes its pass against the winner's row.
 */
final readonly class SyncPlanFromSubscription
{
    public function __construct(
        private CustomerDirectory $directory,
        private Repository $config,
        private BillingEventLog $log,
        private MerchantScopedCustomerDirectory $scopedCustomers,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        $moved = DB::transaction(function () use ($event): ?Model {
            $owner = $this->resolveOwner($event);

            if (! $owner instanceof Model) {
                return null;
            }

            return $this->applyLocked($owner, $event) ? $owner : null;
        });

        $this->announce($moved);
    }

    /**
     * The billable an event is about. A marketplace event names the connected account that ISSUED its
     * customer reference, and a provider customer id is unique only within its account — so the owner is
     * resolved account-scoped, never globally, where the same id under a second merchant would hand back a
     * stranger (their subscription, their tier, their data). A platform event carries no account and uses
     * the global directory, unchanged for a single-seller install.
     */
    private function resolveOwner(SubscriptionStateChanged $event): ?Model
    {
        if ($event->merchantAccountReference !== null) {
            return $this->scopedCustomers->ownerForReference($event->merchantAccountReference, $event->customerReference);
        }

        return $this->directory->ownerForReference($event->customerReference);
    }

    /**
     * Apply the event to a KNOWN owner, resolved outside this effect. The webhook path finds the owner
     * from the event's customer reference; the return-reconcile already has the authenticated owner in
     * hand and calls this directly — so it works even on an install that has not wired billing.customer,
     * where the reference-based lookup would find nobody. Same rules either way.
     */
    public function applyTo(Model $owner, SubscriptionStateChanged $event): void
    {
        $this->announce(DB::transaction(
            fn (): ?Model => $this->applyLocked($owner, $event) ? $owner : null,
        ));
    }

    /**
     * Tell the owner's open screens to re-fetch — AFTER the transaction that earned the right to say so.
     *
     * Inside it would be wrong in a way nothing would catch: the broadcast leaves the process immediately,
     * a rollback cannot recall it, and the screens would re-fetch state that was never written. Deferring
     * it with `ShouldDispatchAfterCommit` is the framework's answer to the same problem and is not usable
     * here — the suite runs inside a transaction that never commits, so the event would never fire in any
     * test and the wiring would be provably present and provably unexercised.
     *
     * Silence is the common case: a provider redelivers freely, and an event that moved nothing must not
     * make every open screen re-fetch.
     */
    private function announce(?Model $owner): void
    {
        if ($owner instanceof Model) {
            Event::dispatch(new AccountBillingUpdated($owner));
        }
    }

    /** @return bool whether this event actually moved anything — the row, or the owner's tier column. */
    private function applyLocked(Model $owner, SubscriptionStateChanged $event): bool
    {
        $subscription = $this->lockRow($owner, $event->merchant);

        // A row that did not exist is itself a change, and `wasChanged()` below cannot see it: the insert
        // already wrote the event's values, so the save that follows finds nothing left to move.
        $appeared = ! $subscription instanceof Subscription;

        if (! $subscription instanceof Subscription) {
            // First delivery. insertOrIgnore, not create: two concurrent first deliveries both read no
            // row (a row that does not exist cannot be locked), so both reach here — the loser's insert
            // must NO-OP rather than raise a unique violation the provider would read as our outage.
            // This is the codebase's create-race idiom (see UsageRecorder). We then re-read under lock:
            // whoever we find is the row to order against — ourselves if we won, the winner if we lost.
            $this->insertRow($owner, $event);
            $subscription = $this->lockRow($owner, $event->merchant);
        }

        // The row is guaranteed to exist now. Order this event against it: an out-of-order or retried
        // OLDER event (including a lost create-race whose winner is newer) is dropped without touching
        // the owner column.
        if (! $subscription instanceof Subscription || $this->isStale($subscription, $event)) {
            return false;
        }

        // A subscription we expired does not come back. Ordering cannot express this — the event is NEWER,
        // and applying it is exactly what must not happen — so it is a second, separate refusal.
        if ($this->isRevival($subscription, $event)) {
            return false;
        }

        $subscription->forceFill($this->attributes($subscription, $event));

        // Asked BEFORE the save, never after. `save()` on a model with nothing dirty returns early without
        // resyncing its change set, so `wasChanged()` keeps answering for the PREVIOUS save — and the second
        // delivery of an identical event would report a change that only the first one made.
        $moved = $appeared || $subscription->isDirty();

        $subscription->save();

        // The denormalized tier column is the owner's ONE hot-path entitlement value, and a single column
        // cannot hold a tier per creator. It mirrors the PLATFORM subscription only; a marketplace creator's
        // tier lives on its own row (keyed by merchant_uid, written above) and is read back through the
        // subscription-state reader — never flattened onto the shared column, where the last creator's
        // webhook would clobber the platform plan every entitlement check keys on. A merchant event has
        // recorded its row and stops here.
        if (! $this->scope($event)->isPlatform()) {
            return $moved;
        }

        $column = $this->string('billing.tier_column', 'plan');

        if (in_array($owner->getAttribute($column), $this->untouchableTiers(), true)) {
            return $moved;
        }

        // Hard dunning: a state that does not grant access pulls the tier to zero.
        if (! $event->state->grantsAccess()) {
            $zero = $this->string('billing.zero_tier', 'free');

            if ($owner->getAttribute($column) !== $zero) {
                // The audit answer to "why is this customer on free?" — the tier was pulled by a
                // non-granting provider state, not by anyone in the app.
                $this->log->record('plan.revoked', $owner, [
                    'to' => $zero,
                    'state' => $event->state->value,
                    'subscription' => $event->subscriptionReference,
                ], AuditSource::Webhook);
            }

            $owner->forceFill([$column => $zero]);
            $pulled = $owner->isDirty();
            $owner->save();

            return $moved || $pulled;
        }

        // Access IS granted. Mirror the row's tier onto the owner column. The row already carries the
        // right value: the save above wrote `event tier ?? last known tier`, so an event whose price
        // maps to no tier (a rotated/grandfathered price, a metered-only subscription) keeps the last
        // tier we resolved. Without that fall-back a single past-due blip would pull the owner to the
        // zero tier and NOTHING would put them back — every later event carries the same unresolvable
        // price — and they would keep paying, on free, forever.
        if (is_string($subscription->tier_key)) {
            if ($owner->getAttribute($column) !== $subscription->tier_key) {
                $this->log->record('plan.granted', $owner, [
                    'tier' => $subscription->tier_key,
                    'subscription' => $event->subscriptionReference,
                ], AuditSource::Webhook);
            }

            $owner->forceFill([$column => $subscription->tier_key]);
            $granted = $owner->isDirty();
            $owner->save();

            return $moved || $granted;
        }

        return $moved;
    }

    /** Whether this event is an out-of-order/retried delivery that must not be applied. */
    private function isStale(Subscription $subscription, SubscriptionStateChanged $event): bool
    {
        // A null event timestamp is maximally-ambiguous ordering: never let it RESURRECT access over a
        // currently non-granting state (that would silently un-suspend a delinquent owner). Revocation
        // on a null timestamp still applies — failing toward less access is safe.
        if ($event->occurredAt === null) {
            return $this->resurrectsAccess($subscription, $event);
        }

        if ($subscription->synced_event_at === null) {
            return false;
        }

        if ($event->occurredAt < $subscription->synced_event_at) {
            return true;
        }

        // Same-second tie: Stripe stamps events to the whole second and does not order within one.
        // Refuse to resurrect access on a tie whose true order is unknowable.
        return $event->occurredAt === $subscription->synced_event_at
            && $this->resurrectsAccess($subscription, $event);
    }

    /**
     * Whether this event would revive a subscription that expired for good.
     *
     * The cure window ends in a decision, not merely in a state: the subscription is over, and a payment
     * after it is a NEW contract. A provider does not know that — it will happily report the same
     * subscription active again after a retried invoice or a reactivation upstream — so the refusal lives
     * here rather than being hoped for.
     *
     * ## It is scoped to the provider subscription, not to the row
     *
     * Uniqueness is (owner, type, merchant_uid), so a customer has exactly one row per merchant and a new
     * signup necessarily re-uses it. Refusing every event on a terminated row would therefore make the
     * customer permanently unable to subscribe to that merchant again — the opposite of "a later payment is
     * a new signup". So the test is the provider reference: the same subscription stays dead, a different
     * one takes the row over and clears the marker.
     *
     * A missing reference on either side cannot distinguish the two, and this fails toward less access for
     * the same reason the staleness guard does: a wrongly refused event is corrected by the next delivery,
     * a wrongly granted one hands back access that was decided away.
     */
    private function isRevival(Subscription $subscription, SubscriptionStateChanged $event): bool
    {
        if (! $subscription->terminated()) {
            return false;
        }

        return $subscription->provider_id === null
            || $event->subscriptionReference === null
            || $event->subscriptionReference === $subscription->provider_id;
    }

    /** Whether applying the event would flip a currently non-granting subscription back to access. */
    private function resurrectsAccess(Subscription $subscription, SubscriptionStateChanged $event): bool
    {
        return ! $this->grants($subscription->status) && $event->state->grantsAccess();
    }

    /** Whether a stored status string represents an access-granting state. */
    private function grants(string $status): bool
    {
        return SubscriptionState::tryFrom($status)?->grantsAccess() === true;
    }

    /**
     * The billable's locked subscription-state row for one merchant scope, or null when none exists yet.
     * The scope is part of the identity: a fan holds one row per creator plus the platform row, so the
     * lock must name which one this event is about or a creator's webhook would order against the platform
     * row. A null merchant is the platform scope, unchanged for a single-seller install.
     */
    private function lockRow(Model $owner, ?MerchantScope $merchant): ?Subscription
    {
        return Subscription::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant($merchant)
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * Insert the first subscription-state row for this owner, ignoring the unique violation a concurrent
     * first delivery would raise. insertOrIgnore rather than create makes the loser of the create-race a
     * no-op instead of a 500; the caller re-reads under lock and orders its event against whatever row won.
     */
    private function insertRow(Model $owner, SubscriptionStateChanged $event): void
    {
        Subscription::query()->insertOrIgnore([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'type' => Subscription::TYPE_DEFAULT,
            ...$this->merchantColumns($this->scope($event)),
            ...$this->attributes(null, $event),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** The event's merchant scope, collapsing a null merchant to the platform. */
    private function scope(SubscriptionStateChanged $event): MerchantScope
    {
        return $event->merchant ?? MerchantScope::platform();
    }

    /**
     * The raw merchant columns for a first insert. `merchant_uid` is the authoritative NOT-NULL key every
     * query scopes on; the nullable morph pair is the convenience relation and stays null for the platform.
     * insertOrIgnore bypasses the model's attribute default, so the uid is set explicitly here — a missing
     * one would default to `platform` at the database and silently mis-key a creator's row onto the platform.
     *
     * @return array<string, mixed>
     */
    private function merchantColumns(MerchantScope $merchant): array
    {
        return [
            'merchant_uid' => $merchant->uid(),
            'merchant_type' => $merchant->isPlatform() ? null : $merchant->type,
            'merchant_id' => $merchant->isPlatform() ? null : $merchant->id,
        ];
    }

    /**
     * The row attributes an event resolves to. An event that conveys no tier, recency or cycle must never
     * erase a known one, so each of those falls back to the existing row's value (null on a first insert).
     *
     * @return array<string, mixed>
     */
    private function attributes(?Subscription $subscription, SubscriptionStateChanged $event): array
    {
        $delinquentSince = $this->delinquentSince($subscription, $event->state);

        return [
            'provider' => $this->string('billing.default', 'stripe'),
            'provider_id' => $event->subscriptionReference,
            'status' => $event->state->value,
            // Never overwrite a known tier with null: an event whose price maps to no tier tells us
            // nothing about the tier, so the last one we resolved still stands.
            'tier_key' => $event->tierKey ?? $subscription?->tier_key,
            'delinquent_since' => $delinquentSince,
            // Always null, and that is not the same as "never terminated". A terminated row only reaches
            // this line when the event carries a DIFFERENT provider subscription — isRevival above dropped
            // every event for the terminated one — so getting here means a new signup has taken the row
            // over, and it starts with a clean slate. Writing the marker away here rather than leaving it
            // is what keeps the new subscription from inheriting the old one's ending.
            'terminated_at' => null,
            // The dunning-ladder rung already notified rides the delinquency clock: reset it to 0 the
            // moment the subscription recovers, so a later relapse starts the escalation from scratch
            // rather than being suppressed by a stale level.
            'dunning_level' => $delinquentSince instanceof Carbon ? ($subscription instanceof Subscription ? $subscription->dunning_level : 0) : (0),
            // Never overwrite a known recency with null — a null-timestamped event must not disable the
            // out-of-order guard for every future event.
            'synced_event_at' => $event->occurredAt ?? $subscription?->synced_event_at,
            // The cycle metered usage is billed into. Same rule: an event that conveys no cycle must not
            // erase the one we know, or usage would fall back to a calendar month mid-cycle.
            'current_period_start' => $this->moment($event->periodStart) ?? $subscription?->current_period_start,
            'current_period_end' => $this->moment($event->periodEnd) ?? $subscription?->current_period_end,
            // The subscription trial's end, so the trial banner and the trial CTA can read the days left.
            // Same never-erase rule: an event with no trial end keeps the one we know.
            'trial_ends_at' => $this->moment($event->trialEnd) ?? $subscription?->trial_ends_at,
        ];
    }

    /**
     * The delinquency-clock timestamp for the new state: start it when the subscription first enters a
     * blocking state, keep it running while it stays blocking, and clear it once it recovers. The clock
     * drives the dunning + suspension ladders, so it must not be reset by every blocking event.
     */
    private function delinquentSince(?Subscription $subscription, SubscriptionState $state): ?Carbon
    {
        if (! $state->isBlocking()) {
            return null;
        }

        $existing = $subscription instanceof Subscription ? $subscription->delinquent_since : null;

        return $existing ?? Carbon::now();
    }

    /** A provider Unix timestamp as a UTC moment, or null when the provider conveyed none. */
    private function moment(?int $timestamp): ?Carbon
    {
        return $timestamp === null ? null : Carbon::createFromTimestampUTC($timestamp);
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /** @return list<string> */
    private function untouchableTiers(): array
    {
        $tiers = $this->config->get('billing.untouchable_tiers', []);

        return is_array($tiers) ? array_values(array_filter($tiers, is_string(...))) : [];
    }
}
