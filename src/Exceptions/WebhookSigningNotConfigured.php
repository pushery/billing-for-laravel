<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when a driver's webhook signature verification is not configured in production —
 * an empty Stripe signing secret, a missing HMAC key, etc. Failing loud prevents silently
 * accepting unverified webhooks.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
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
