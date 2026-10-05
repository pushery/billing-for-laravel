<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\EndsNowOnTheOwnersRequest;
use Pushery\Billing\Contracts\ReconfirmsIdentity;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Livewire\Concerns\ConfirmsIdentity;

/**
 * The account-hub danger zone. It ends the subscription immediately (no grace period). Ending now is the only
 * irreversible action here, so it is deliberately isolated on its own screen and re-confirms the acting user's
 * identity (throttled) before it fires. A password account proves its password, so a hijacked session cannot end
 * its billing blind; a passwordless account proves its account email unless the application binds
 * {@see ReconfirmsIdentity} to a factor of its own, and an email stops an accidental click, not a held session.
 *
 * This is the owner's own request to leave, not account deletion, which ends every contract through the
 * `BillableAccountDeleting` listener. So a driver that bills a period at its end is asked to end it with
 * {@see EndsNowOnTheOwnersRequest::endNow()}, which bills the days of the period the owner has already had:
 * they were provided, and `cancelNow()` bills nothing more. A driver that collects in advance has been paid for
 * those days already and is asked to `cancelNow()`.
 */
final class DangerZone extends AccountScreen
{
    use ConfirmsIdentity;

    /** Whether the irreversible cancel-now action is armed (a two-step confirm, no wire:confirm). */
    public bool $confirming = false;

    /** The re-confirm credential the acting user types (by default a password, or their email for an OAuth account).
     *  Client input, taken out of the property before ConfirmsIdentity checks it — never persisted. */
    public string $credential = '';

    protected function headingKey(): string
    {
        return 'billing::account.danger.heading';
    }

    public function render(): View
    {
        // The prompt is asked of the bound re-confirmation only while the field is shown: it names what that binding
        // checks, a password, an account email or a factor of the application's own.
        return $this->view('billing::livewire.danger-zone', [
            'prompt' => $this->confirming ? $this->reconfirmationPrompt() : null,
        ]);
    }

    public function confirm(): void
    {
        $this->confirming = true;
    }

    public function abort(): void
    {
        $this->confirming = false;
        $this->credential = '';
    }

    public function cancelNow(): void
    {
        // The secret leaves the property before anything can throw. A refusal is a ValidationException,
        // and Livewire answers it with the component's state: a secret still held here would travel back in
        // that snapshot, and in every later request of the screen.
        $credential = $this->credential;
        $this->credential = '';

        // Re-confirm the acting user's identity (throttled) BEFORE the irreversible cancel. On a wrong
        // secret or a throttle lockout this throws and the cancel never runs.
        $this->confirmIdentity($credential);

        $actions = $this->subscriptionActions();

        if ($actions instanceof EndsNowOnTheOwnersRequest) {
            $actions->endNow($this->owner());
        } else {
            $actions->cancelNow($this->owner());
        }

        $this->audit('subscription.canceled', ['immediate' => true]);

        $this->confirming = false;
    }

    /**
     * The bound actions, typed as the contract they are bound to.
     *
     * Which driver answers is the container's decision and changes with the install, so the screen asks the
     * contract and then whether the answer can also end an owner's subscription on their request.
     */
    private function subscriptionActions(): SubscriptionActions
    {
        return Container::getInstance()->make(SubscriptionActions::class);
    }
}
