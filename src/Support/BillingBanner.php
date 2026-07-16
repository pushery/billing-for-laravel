<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\BannerNotice;

/**
 * Resolves the single app-shell banner an owner should see about their billing, or null when nothing
 * needs their attention (the common case). It reads only the local subscription row (via the presenter)
 * plus the trial clock — no provider call — so it is cheap enough to run on every shell render and safe
 * during a provider outage. With no subscription row it reads the owner itself, so an owner on a generic
 * trial (no subscription) is nudged as their trial lapses, not left silent. Precedence: a blocked payment
 * outranks a lapsing grace period, which outranks a trial about to end.
 */
final readonly class BillingBanner
{
    public function __construct(
        private SubscriptionPresenter $presenter,
        private Repository $config,
        private Trials $trials,
    ) {}

    public function for(Model $owner): ?BannerNotice
    {
        $subscription = $this->subscription($owner);

        $snapshot = $subscription instanceof Subscription
            ? $subscription->toSnapshot()
            : $this->trials->ownerSnapshot($owner);

        $state = $this->presenter->present($snapshot);

        return match (true) {
            $state === SubscriptionState::PastDue => $this->notice($state, 'danger', 'past_due', 'recover', 'billing.account.recovery'),
            $state === SubscriptionState::Incomplete => $this->notice($state, 'warning', 'incomplete', 'confirm', 'billing.account.recovery'),
            $state === SubscriptionState::Grace => $this->notice($state, 'warning', 'grace', 'resume', 'billing.account.subscription'),
            // Without this the pause is invisible: the owner's paid features quietly stop working and
            // nothing on the account tells them why, or that one click restores them.
            $state === SubscriptionState::Paused => $this->notice($state, 'warning', 'paused', 'resume', 'billing.account.subscription'),
            // Covers both a subscription trial (Trialing) and a generic trial (GenericTrial, no row) —
            // both read isTrialing(), and the trial clock falls back to the owner's own column.
            $state->isTrialing() && $this->trialEndingSoon($subscription, $owner) => $this->notice($state, 'info', 'trial_ending', 'upgrade', 'billing.account.plan'),
            default => null,
        };
    }

    private function notice(SubscriptionState $state, string $intent, string $message, string $cta, string $route): BannerNotice
    {
        return new BannerNotice(
            state: $state,
            intent: $intent,
            messageKey: 'billing::account.banner.'.$message,
            ctaKey: 'billing::account.banner.cta.'.$cta,
            ctaRoute: $route,
        );
    }

    private function subscription(Model $owner): ?Subscription
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();
    }

    /** Whether the trial ends within the configured warning window (default 3 days). */
    private function trialEndingSoon(?Subscription $subscription, Model $owner): bool
    {
        // The trial clock is the subscription's trial end when there is one; otherwise the owner's own
        // column — a generic trial has no row, and a webhook-synced trialing row carries the status but
        // sometimes no date. Checking the subscription (not its property) keeps the fallback for BOTH a
        // missing row and a row whose trial date is null.
        $endsAt = ($subscription instanceof Subscription ? $subscription->trial_ends_at : null)
            ?? $this->ownerTrialEnd($owner);

        if (! $endsAt instanceof DateTimeInterface) {
            return false;
        }

        $within = $this->config->get('billing.trial.ending_within_days', 3);
        $within = is_int($within) ? $within : 3;

        $now = Carbon::now();

        return $endsAt > $now && Carbon::instance($endsAt) <= $now->copy()->addDays($within);
    }

    private function ownerTrialEnd(Model $owner): ?DateTimeInterface
    {
        $endsAt = $owner->getAttribute('trial_ends_at');

        return $endsAt instanceof DateTimeInterface ? $endsAt : null;
    }
}
