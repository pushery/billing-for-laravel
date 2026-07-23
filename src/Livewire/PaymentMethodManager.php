<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\PaymentMethods;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * The account-hub payment-method screen: it lists the owner's stored methods (the default first), sets
 * a new default, removes a method, and opens the add-a-method flow by
 * redirecting to the provider's hosted card page. The list/remove/set-default trio is the superset closure over apps that
 * could only ever add a single card.
 *
 * The method id is a client-supplied action argument (`wire:click="remove('pm_x')"`), so both mutating
 * verbs check it against the owner's OWN methods before acting — an id for another account resolves to a
 * 404 here, and the driver re-checks it against the Stripe customer as defense in depth.
 */
final class PaymentMethodManager extends AccountScreen
{
    use DegradesGracefully;

    public function render(): View
    {
        return $this->view('billing::livewire.payment-method-manager', [
            // Listing methods is a provider read; degrade to a notice rather than 500 the whole screen.
            'methods' => $this->orDegrade(fn (): array => app(PaymentMethods::class)->all($this->owner()), []),
        ]);
    }

    public function addMethod(): void
    {
        $this->ensureEligible();

        // A full-page redirect to the provider's hosted card page — no card data touches this app, and no
        // front-end element is shipped. The card is captured on the provider's side and attached to the
        // customer; the customer returns here. A driver with no hosted page yields null and nothing happens.
        $url = SafeExternalUrl::orNull(app(PaymentMethods::class)->addMethodUrl($this->owner()));

        if ($url !== null) {
            $this->redirect($url);
        }
    }

    public function setDefault(string $methodId): void
    {
        $this->authorizeMethod($methodId);

        app(PaymentMethods::class)->setDefault($this->owner(), $methodId);
    }

    public function remove(string $methodId): void
    {
        $this->authorizeMethod($methodId);

        // Self-harm guard: removing the default (or only) card while a live subscription will still be
        // charged strands the next charge and sends the owner into involuntary dunning. Refuse with a clear
        // message — set another card as default first — rather than a 500 or a silent lapse. This is a
        // correctness guard, not a security one: ownership is already enforced by authorizeMethod().
        if ($this->wouldStrandBilling($methodId)) {
            $this->addError('remove', __('billing::account.payment_methods.cannot_remove_last_default'));

            return;
        }

        app(PaymentMethods::class)->remove($this->owner(), $methodId);
    }

    /**
     * Whether removing this method would leave a live subscription with no card to bill: the method is the
     * default or the owner's only one, AND the subscription is in a state that still needs a payment method
     * (a charge is coming or is being retried). Grace and the terminal states have no charge coming, so
     * removing a card there is harmless.
     */
    private function wouldStrandBilling(string $methodId): bool
    {
        if (! $this->currentState()->requiresPaymentMethod()) {
            return false;
        }

        $methods = app(PaymentMethods::class)->all($this->owner());

        if (count($methods) === 1) {
            return true;
        }

        return array_any(
            $methods,
            static fn (PaymentMethod $method): bool => $method->id === $methodId && $method->isDefault,
        );
    }

    /**
     * Reject a mutation for a method the signed-in owner does not actually hold. The id arrives from the
     * browser, so it is checked against the owner's own stored methods — an id belonging to another
     * account is a 404, never a mutation.
     */
    private function authorizeMethod(string $methodId): void
    {
        $owned = array_map(
            static fn (PaymentMethod $method): string => $method->id,
            app(PaymentMethods::class)->all($this->owner()),
        );

        abort_unless(in_array($methodId, $owned, true), 404);
    }
}
