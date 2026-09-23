<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\Livewire\Concerns\PollsWhileActivating;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\Support\SubscriptionPresenter;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The account-hub payment-recovery screen. When a payment has failed (a subscription is past due) it
 * guides the owner to fix their payment method so the provider can retry; otherwise it simply reports
 * that nothing needs recovering. Fixing the method redirects to the same hosted card page the
 * payment-methods screen uses.
 *
 * It reads every contract the owner holds, not only the platform's own. In a marketplace the paying
 * subscriptions are the merchants', and the card the hosted page collects becomes the default for the
 * owner's account and for every subscription that names a card of its own, a merchant's included. So a
 * merchant's past-due subscription is repaired by the same action, and this screen is where the banner
 * sends an owner whose only failed payment is one.
 */
final class PaymentRecovery extends AccountScreen
{
    use DegradesGracefully;
    use PollsWhileActivating;

    public function render(): View
    {
        $states = [$this->currentState(), ...$this->otherContractStates()];
        $pastDue = in_array(SubscriptionState::PastDue, $states, true);
        $incomplete = in_array(SubscriptionState::Incomplete, $states, true);
        $recovering = $pastDue || $incomplete;

        return $this->view('billing::livewire.payment-recovery', [
            'needsRecovery' => $pastDue,
            // While recovery is in flight, poll (bounded) until the provider's retry / 3-D Secure settles the
            // state. It runs WHETHER OR NOT realtime is on, and that is deliberate: a broadcast notifies the
            // owner, it does not re-render this screen, so gating the poll on realtime would leave the
            // transition with no refresh at all. {@see PollsWhileActivating} for the full reasoning.
            'poll' => $this->activationPoll($recovering),
            // Incomplete is a DIFFERENT problem from past-due: the payment needs the cardholder to
            // confirm it (3-D Secure), not a new card. The banner already prompts "confirm payment";
            // without this branch the recovery screen answered "all good" to the same owner.
            'needsConfirmation' => $incomplete,
            // Reading the method on file is a provider read; degrade to a notice rather than 500 the screen.
            'default' => $this->orDegrade(fn (): ?PaymentMethod => Container::getInstance()->make(PaymentMethods::class)->default($this->owner())),
        ]);
    }

    public function updatePaymentMethod(): void
    {
        $this->ensureEligible();

        // A past-due owner is sent to the provider's hosted card page to replace the method that failed.
        // When the page completes, the new card becomes the default for the account and for every
        // subscription that names a card of its own (AdoptCollectedPaymentMethod), so the provider's next
        // retry charges it. That retry comes on the provider's schedule, not on return. No card data
        // touches this app.
        $url = SafeExternalUrl::orNull(Container::getInstance()->make(PaymentMethods::class)->addMethodUrl($this->owner()));

        if ($url !== null) {
            $this->redirect($url);
        }
    }

    /**
     * The state of every other contract the owner holds: the platform's subscriptions of another type,
     * and each merchant's.
     *
     * Presented the way the banner presents them, from the local rows alone, so the screen and the notice
     * that links to it agree about which contract needs recovering.
     *
     * @return list<SubscriptionState>
     */
    private function otherContractStates(): array
    {
        $query = Subscription::model()::query()->forOwner($this->owner());
        $managed = $this->subscription();

        if ($managed instanceof Subscription) {
            $query->whereKeyNot($managed->getKey());
        }

        $presenter = Container::getInstance()->make(SubscriptionPresenter::class);

        return array_values($query->get()->map(
            static fn (Subscription $subscription): SubscriptionState => $presenter->present($subscription->toSnapshot()),
        )->all());
    }
}
