<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\WebhookEffectMiddleware;
use Pushery\Billing\Webhooks\HandleWebhookEffect;

/**
 * The shipped default: every webhook effect runs without job middleware.
 *
 * Which share of the queue an account may take is the application's to decide, and so is the limiter
 * that keys on it. Naming none keeps each effect running the moment a worker takes it.
 */
final readonly class NoWebhookEffectMiddleware implements WebhookEffectMiddleware
{
    public function for(HandleWebhookEffect $job): array
    {
        return [];
    }
}
