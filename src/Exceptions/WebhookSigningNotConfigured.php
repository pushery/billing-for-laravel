<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when a driver's webhook signature verification is not configured in production —
 * an empty Stripe signing secret, a missing Adyen HMAC key, etc. Failing loud prevents silently
 * accepting unverified webhooks.
 */
final class WebhookSigningNotConfigured extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(
            "Webhook signature verification for the '{$driver}' driver is not configured. Set its "
            .'signing secret before accepting webhooks in production.'
        );
    }
}
