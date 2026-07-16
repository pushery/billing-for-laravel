<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;

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
 * concurrent FIRST deliveries can both read no row and both insert; the loser's unique violation reruns
 * the effect against the now-existing row instead of answering the provider with a 500.
 */
final readonly class SyncPlanFromSubscription
{
    public function __construct(
        private CustomerDirectory $directory,
        private Repository $config,
        private BillingEventLog $log,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        DB::transaction(function () use ($event): void {
            $owner = $this->directory->ownerForReference($event->customerReference);

            if (! $owner instanceof Model) {
                return;
            }

            $this->applyLocked($owner, $event);
        });
    }

    /**
     * Apply the event to a KNOWN owner, resolved outside this effect. The webhook path finds the owner
     * from the event's customer reference; the return-reconcile already has the authenticated owner in
     * hand and calls this directly — so it works even on an install that has not wired billing.customer,
     * where the reference-based lookup would find nobody. Same rules either way.
     */
    public function applyTo(Model $owner, SubscriptionStateChanged $event): void
    {
        DB::transaction(fn () => $this->applyLocked($owner, $event));
    }

    private function applyLocked(Model $owner, SubscriptionStateChanged $event): void
    {
        $subscription = $this->lockRow($owner);

        if (! $subscription instanceof Subscription) {
            // First delivery. insertOrIgnore, not create: two concurrent first deliveries both read no
            // row (a row that does not exist cannot be locked), so both reach here — the loser's insert
            // must NO-OP rather than raise a unique violation the provider would read as our outage.
            // This is the codebase's create-race idiom (see UsageRecorder). We then re-read under lock:
            // whoever we find is the row to order against — ourselves if we won, the winner if we lost.
            $this->insertRow($owner, $event);
            $subscription = $this->lockRow($owner);
        }

        // The row is guaranteed to exist now. Order this event against it: an out-of-order or retried
        // OLDER event (including a lost create-race whose winner is newer) is dropped without touching
        // the owner column.
        if (! $subscription instanceof Subscription || $this->isStale($subscription, $event)) {
            return;
        }

        $subscription->forceFill($this->attributes($subscription, $event))->save();

        $column = $this->string('billing.tier_column', 'plan');

        if (in_array($owner->getAttribute($column), $this->untouchableTiers(), true)) {
            return;
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

            $owner->forceFill([$column => $zero])->save();

            return;
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

            $owner->forceFill([$column => $subscription->tier_key])->save();
        }
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

    /** The billable's locked subscription-state row, or null when none exists yet. */
    private function lockRow(Model $owner): ?Subscription
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
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
            'type' => 'default',
            ...$this->attributes(null, $event),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
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
