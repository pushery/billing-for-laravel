<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * Authenticates an incoming provider webhook by its signature (e.g. a Stripe signing secret or an HMAC key).
 * The receiver rejects anything this returns false for BEFORE parsing it, so an unsigned or forged
 * payload never reaches an effect. Each driver ships its own verifier.
 */
interface WebhookVerifier
{
    public function verify(Request $request): bool;
}
