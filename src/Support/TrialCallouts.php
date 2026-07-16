<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\ValueObjects\TrialCallout;

/**
 * Resolves the ONE call-to-action an account screen shows while an owner is on a trial, or null when they
 * are not — so every screen renders exactly one trial CTA, chosen the same way. Policy-driven: a generic
 * trial (no subscription yet) points at picking a plan; a subscription trial started WITHOUT a card points
 * at adding one before it converts; a subscription trial that already has a card points at reviewing the
 * plan. It reads only local columns (the trial clock, the default-payment-method marker) — no provider
 * call — so it is cheap enough to run on every screen render.
 */
final readonly class TrialCallouts
{
    public function for(Model $owner, SubscriptionState $state, ?DateTimeInterface $trialEndsAt = null): ?TrialCallout
    {
        if (! $state->isTrialing()) {
            return null;
        }

        $daysLeft = $this->daysLeft($trialEndsAt ?? $this->ownerTrialEnd($owner));

        // A generic trial has no subscription yet: the one action is to pick a plan and become a subscriber.
        if ($state === SubscriptionState::GenericTrial) {
            return new TrialCallout(
                state: $state,
                intent: 'info',
                daysLeft: $daysLeft,
                messageKey: 'billing::account.trial.generic',
                ctaKey: 'billing::account.trial.cta.subscribe',
                ctaRoute: 'billing.account.plan',
            );
        }

        // A subscription trial started without a card on file: the one action is to add one, or the plan
        // cannot continue when the trial converts.
        if (! $this->hasPaymentMethod($owner)) {
            return new TrialCallout(
                state: $state,
                intent: 'warning',
                daysLeft: $daysLeft,
                messageKey: 'billing::account.trial.add_pm',
                ctaKey: 'billing::account.trial.cta.add_payment_method',
                ctaRoute: 'billing.account.payment-methods',
            );
        }

        // A subscription trial with a card on file: all set — the one action is to review or change the plan.
        return new TrialCallout(
            state: $state,
            intent: 'info',
            daysLeft: $daysLeft,
            messageKey: 'billing::account.trial.upgrade',
            ctaKey: 'billing::account.trial.cta.upgrade',
            ctaRoute: 'billing.account.plan',
        );
    }

    /** Whole days until the trial ends (rounded up) — at least 1 while future, 0 once the end has passed. */
    private function daysLeft(?DateTimeInterface $endsAt): int
    {
        if (! $endsAt instanceof DateTimeInterface) {
            return 0;
        }

        $secondsLeft = $endsAt->getTimestamp() - Carbon::now()->getTimestamp();

        return max(0, (int) ceil($secondsLeft / 86400));
    }

    /** Whether the owner has a default payment method on file, read from the local Cashier marker column. */
    private function hasPaymentMethod(Model $owner): bool
    {
        $pmType = $owner->getAttribute('pm_type');

        return is_string($pmType) && $pmType !== '';
    }

    /** The owner's own trial clock (the Cashier column), normalized to a date-time or null. */
    private function ownerTrialEnd(Model $owner): ?DateTimeInterface
    {
        $value = $owner->getAttribute('trial_ends_at');

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
