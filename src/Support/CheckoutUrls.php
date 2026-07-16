<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The URLs a hosted checkout (and the hosted portal) return the customer to. A consumer may pin them in
 * config('billing.checkout.*'); when they do not, they fall back to the account hub's own routes — so a
 * fresh install can open checkout without any URL configuration, instead of hitting the RuntimeException
 * the driver used to throw on the null default.
 */
final readonly class CheckoutUrls
{
    public function __construct(private Repository $config) {}

    /** Where the provider returns a completed checkout — the reconcile route that writes the local row. */
    public function successUrl(): string
    {
        return $this->configured('success_url')
            ?? $this->route('billing.account.checkout-return')
            ?? throw new RuntimeException('Configure billing.checkout.success_url, or enable the account hub so its checkout-return route exists.');
    }

    /** Where the provider returns an abandoned checkout — back to the plan screen by default. */
    public function cancelUrl(): string
    {
        return $this->configured('cancel_url')
            ?? $this->route('billing.account.plan')
            ?? throw new RuntimeException('Configure billing.checkout.cancel_url, or enable the account hub so its plan route exists.');
    }

    /**
     * Where a hosted "add a card" page returns the customer — the payment-methods screen by default, so a
     * fresh install needs no configuration. Nullable, like the portal: with the hub off and nothing
     * configured, card capture degrades to unavailable rather than throwing.
     */
    public function paymentMethodsReturnUrl(): ?string
    {
        return $this->configured('payment_methods_return_url')
            ?? $this->route('billing.account.payment-methods');
    }

    /**
     * Where the hosted billing portal returns the customer, or null when none can be resolved. Nullable
     * on purpose: the hosted portal degrades to "unavailable" rather than erroring when it has no return
     * URL, so this must be able to say "none" instead of throwing.
     */
    public function portalReturnUrl(): ?string
    {
        return $this->configured('portal_return_url')
            ?? $this->configured('success_url')
            ?? $this->route('billing.account.subscription');
    }

    /** A non-empty configured checkout URL, or null. */
    private function configured(string $key): ?string
    {
        $value = $this->config->get("billing.checkout.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The absolute URL of a hub route when it is registered, or null when the hub is off. */
    private function route(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }
}
