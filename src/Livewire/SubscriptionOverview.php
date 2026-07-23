<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Enums\CancellationReason;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\Livewire\Concerns\PollsWhileActivating;
use Pushery\Billing\Models\CancellationSurveyRecord;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\TrialCallouts;
use Pushery\Billing\ValueObjects\CancellationSurvey;
use Pushery\Billing\ValueObjects\Money;

/**
 * The account-hub subscription screen. It shows the owner's canonical subscription state (collapsed by
 * the SubscriptionPresenter from the local state row) and a best-effort next-invoice preview, and lets
 * the owner cancel into the grace period or resume from it. Every provider read is null-tolerant, so
 * the screen degrades gracefully rather than erroring when the provider cannot answer.
 */
final class SubscriptionOverview extends AccountScreen
{
    use DegradesGracefully;
    use PollsWhileActivating;

    /** Set by the checkout-return redirect (?activating=1): the subscription is not recorded yet, so the
     * screen shows "activating" and polls until the webhook lands. Reset once the state is no longer pending. */
    #[Url]
    public bool $activating = false;

    /** The optional churn-survey reason the owner picks when canceling (a CancellationReason value, or null
     * for "prefer not to say"). Bound from the cancel form; never required. */
    public ?string $cancelReason = null;

    /** The optional free-text detail, used only when the reason is "other". */
    public ?string $cancelDetail = null;

    public function render(): View
    {
        $state = $this->currentState($this->activating);

        // Once the subscription is actually live (or otherwise settled), drop the pending flag so the poll and
        // the ?activating query param clear themselves.
        if ($state !== SubscriptionState::Activating) {
            $this->activating = false;
        }

        return $this->view('billing::livewire.subscription-overview', [
            'state' => $state,
            // Post-checkout the state is "activating" until the webhook lands; with broadcasting off, fall back
            // to a bounded poll that refreshes until it settles, then stops (never a permanent poll).
            'poll' => $this->activationPoll($state === SubscriptionState::Activating),
            // The next-invoice preview is the one live provider read on this screen. Only an active or
            // subscription-backed trialing state has a next invoice, so skip the call entirely for every other
            // state (it could only answer null) — and degrade it to a notice rather than 500 when it is made.
            'preview' => $state->hasUpcomingInvoice()
                ? $this->orDegrade(fn () => app(UpcomingInvoice::class)->preview($this->owner()))
                : null,
            // The owner's credit balance, so the credit they earned is finally visible and its effect
            // explained. Null when they have none, so the card only shows when there is something to show.
            'credit' => $this->creditBalance(),
            // The one trial CTA for this state (null unless trialing), so a trialing owner sees exactly one
            // next step and no other state CTA competes with it.
            'trial' => app(TrialCallouts::class)->for($this->owner(), $state, $this->subscription()?->trial_ends_at),
            // When access ends (grace) or ended, from the LOCAL subscription column — never a provider call.
            'endsAt' => $this->subscription()?->ends_at,
            // Only offer the hosted-portal link when the active driver actually has one — a driver without a
            // portal (e.g. a local-engine provider) would otherwise show a link that only 404s.
            'supportsHostedPortal' => $this->supportsHostedPortal(),
        ]);
    }

    /** Whether the active driver exposes a hosted billing portal — a config-driven capability, never a call. */
    private function supportsHostedPortal(): bool
    {
        return app(BillingManager::class)->capabilities()->supportsHostedPortal;
    }

    /** The owner's credit balance in the default currency, or null when it is not positive. */
    private function creditBalance(): ?Money
    {
        $currency = app(Repository::class)->get('billing.currency', 'EUR');
        $currency = is_string($currency) && $currency !== '' ? $currency : 'EUR';

        $balance = app(CreditLedger::class)->balanceFor($this->owner(), $currency);

        return $balance->isPositive() ? $balance : null;
    }

    public function cancel(): void
    {
        $survey = $this->cancellationSurvey();

        // Record the churn reason locally only when one was actually given, then cancel. The survey is also
        // handed to the driver, where a provider with a native cancellation-feedback field receives it.
        if ($survey instanceof CancellationSurvey) {
            CancellationSurveyRecord::record($this->owner(), $survey);
        }

        app(SubscriptionActions::class)->cancel($this->owner(), $survey);

        // Deliberately NO identity re-confirm here: canceling is reversible (resume() exists), so it is
        // covered by auth + owner-scoping — a re-confirm is reserved for the irreversible (account deletion,
        // DangerZone). That boundary is a settled design decision, not an oversight. The survey likewise
        // never gates the cancellation: leaving must stay one action.
        $this->audit('subscription.canceled', $survey instanceof CancellationSurvey ? ['reason' => $survey->reason->value] : []);

        $this->reset('cancelReason', 'cancelDetail');
    }

    /**
     * Build the optional cancellation survey from the form input. No reason chosen → null, and the
     * cancellation proceeds regardless: a survey that could stop someone leaving is a dark pattern. A chosen
     * reason is validated for SHAPE only (a known enum value), so a tampered value is a 422, not a stored
     * junk row; "other" additionally requires a detail, surfaced as a 422 rather than the DTO's 500.
     */
    private function cancellationSurvey(): ?CancellationSurvey
    {
        if ($this->cancelReason === null || $this->cancelReason === '') {
            return null;
        }

        $this->validate([
            'cancelReason' => [Rule::enum(CancellationReason::class)],
            'cancelDetail' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = CancellationReason::from($this->cancelReason);

        $detail = $this->cancelDetail !== null && trim($this->cancelDetail) !== '' ? $this->cancelDetail : null;

        if ($reason->detailRequired() && $detail === null) {
            throw ValidationException::withMessages([
                'cancelDetail' => __('billing::account.cancel_survey.detail_required'),
            ]);
        }

        return new CancellationSurvey($reason, $detail);
    }

    public function resume(): void
    {
        app(SubscriptionActions::class)->resume($this->owner());

        $this->audit('subscription.resumed');
    }
}
