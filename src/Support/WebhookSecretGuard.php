<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Exceptions\WebhookSigningNotConfigured;

/**
 * The boot-time fail-loud guard: in production, a driver that verifies webhook signatures must have
 * its secret configured, or the app refuses to boot rather than accept unverified webhooks. Outside
 * production (and for fetch-only drivers with no secret) it is silent.
 */
final class WebhookSecretGuard
{
    public function ensureConfigured(string $driver, string $environment, ?string $secret): void
    {
        if ($environment === 'production' && ($secret === null || trim($secret) === '')) {
            throw WebhookSigningNotConfigured::forDriver($driver);
        }
    }
}
