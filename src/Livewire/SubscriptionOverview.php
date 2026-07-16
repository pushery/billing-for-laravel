<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\TrialCallouts;
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

    public function render(): View
    {
        $state = $this->currentState();

        return $this->view('billing::livewire.subscription-overview', [
            'state' => $state,
            // The next-invoice preview is a provider read; degrade it to a notice rather than 500 the screen.
            'preview' => $this->orDegrade(fn () => app(UpcomingInvoice::class)->preview($this->owner())),
            // The owner's credit balance, so the credit they earned is finally visible and its effect
            // explained. Null when they have none, so the card only shows when there is something to show.
            'credit' => $this->creditBalance(),
            // The one trial CTA for this state (null unless trialing), so a trialing owner sees exactly one
            // next step and no other state CTA competes with it.
            'trial' => app(TrialCallouts::class)->for($this->owner(), $state, $this->subscription()?->trial_ends_at),
        ]);
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
        app(SubscriptionActions::class)->cancel($this->owner());

        $this->audit('subscription.canceled');
    }

    public function resume(): void
    {
        app(SubscriptionActions::class)->resume($this->owner());

        $this->audit('subscription.resumed');
    }
}
