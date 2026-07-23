<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The extra Content-Security-Policy sources a driver's client-side payment element needs. The account
 * hub scopes these to its own route group only — so the payment provider's scripts and frames load on
 * the billing screens and NOWHERE else in the host app. Each driver declares its own origins (Stripe
 * loads js.stripe.com; another driver declares theirs), keeping the CSP per-driver.
 */
interface PaymentCsp
{
    /**
     * CSP sources to allow, keyed by directive — e.g. ['script-src' => ['https://js.stripe.com']].
     * Merged into the account hub's scoped policy on top of a self-only, Livewire-safe base.
     *
     * @return array<string, list<string>>
     */
    public function directives(): array;
}
