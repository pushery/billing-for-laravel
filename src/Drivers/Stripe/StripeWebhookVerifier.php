<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\WebhookVerifier;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Authenticates an incoming Stripe webhook by its signature against the configured signing secret
 * (Cashier's `cashier.webhook.secret`). The receiver rejects anything this returns false for before
 * parsing it, so an unsigned or forged payload never reaches an effect. A missing secret, a missing
 * signature header, a bad signature and an unparseable payload all fail closed to false.
 */
final readonly class StripeWebhookVerifier implements WebhookVerifier
{
    public function __construct(private Repository $config) {}

    public function verify(Request $request): bool
    {
        $secret = $this->config->get('cashier.webhook.secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $signature = $request->header('Stripe-Signature');

        if (! is_string($signature)) {
            return false;
        }

        $tolerance = $this->config->get('cashier.webhook.tolerance', 300);
        $tolerance = is_int($tolerance) ? $tolerance : 300;

        try {
            Webhook::constructEvent($request->getContent(), $signature, $secret, $tolerance);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return false;
        }

        return true;
    }
}
