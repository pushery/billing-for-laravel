<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Livewire\Concerns\ConfirmsIdentity;

/**
 * The account-hub danger zone. It stops billing immediately (no grace period) — the hook an app calls
 * from its own account-deletion flow so a deleted account is not left paying. cancelNow is the only
 * irreversible action here, so it is deliberately isolated on its own screen and re-confirms the acting
 * user's identity (throttled) before it fires — a hijacked session cannot end someone's billing blind.
 */
final class DangerZone extends AccountScreen
{
    use ConfirmsIdentity;

    /** Whether the irreversible cancel-now action is armed (a two-step confirm, no wire:confirm). */
    public bool $confirming = false;

    /** The re-confirm secret the acting user types (a password, or their email for an OAuth account).
     *  Client input, verified by ConfirmsIdentity and cleared straight after — never persisted. */
    public string $credential = '';

    public function render(): View
    {
        return $this->view('billing::livewire.danger-zone');
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
        // Re-confirm the acting user's identity (throttled) BEFORE the irreversible cancel. On a wrong
        // secret or a throttle lockout this throws and the cancel never runs; the credential is dropped
        // the moment the action returns, either way.
        $this->confirmIdentity($this->credential);

        app(SubscriptionActions::class)->cancelNow($this->owner());

        $this->audit('subscription.canceled', ['immediate' => true]);

        $this->credential = '';
        $this->confirming = false;
    }
}
