<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The account-hub payment-recovery screen. When a payment has failed (the subscription is past due) it
 * guides the owner to fix their payment method so the provider can retry; otherwise it simply reports
 * that nothing needs recovering. Fixing the method redirects to the same hosted card page the
 * payment-methods screen uses.
 */
final class PaymentRecovery extends AccountScreen
{
    use DegradesGracefully;

    public function render(): View
    {
        $state = $this->currentState();

        return $this->view('billing::livewire.payment-recovery', [
            'needsRecovery' => $state === SubscriptionState::PastDue,
            // Incomplete is a DIFFERENT problem from past-due: the payment needs the cardholder to
            // confirm it (3-D Secure), not a new card. The banner already prompts "confirm payment";
            // without this branch the recovery screen answered "all good" to the same owner.
            'needsConfirmation' => $state === SubscriptionState::Incomplete,
            // Reading the method on file is a provider read; degrade to a notice rather than 500 the screen.
            'default' => $this->orDegrade(fn (): ?PaymentMethod => app(PaymentMethods::class)->default($this->owner())),
        ]);
    }

    public function updatePaymentMethod(): void
    {
        $this->ensureEligible();

        // A past-due owner is sent to the provider's hosted card page to replace the method that failed;
        // the provider retries against the new card on return. No card data touches this app.
        $url = app(PaymentMethods::class)->addMethodUrl($this->owner());

        if (is_string($url) && $url !== '') {
            $this->redirect($url);
        }
    }
}
