<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\MarketplaceWebhookVerifier;

/**
 * The merchant endpoint's verifier: the same signature check, against the OTHER secret.
 *
 * It wraps rather than duplicates, because a second copy of a signature check is a second place for it to
 * be got wrong — and the two would drift on the day one of them learned about a new header or a changed
 * tolerance. What differs between the two endpoints is one config key, so one config key is what differs
 * here.
 */
final readonly class StripeMarketplaceWebhookVerifier implements MarketplaceWebhookVerifier
{
    public const string SECRET_KEY = 'billing.marketplace.webhook.secret';

    private StripeWebhookVerifier $signature;

    public function __construct(Repository $config)
    {
        $this->signature = new StripeWebhookVerifier($config, self::SECRET_KEY);
    }

    public function verify(Request $request): bool
    {
        return $this->signature->verify($request);
    }
}
