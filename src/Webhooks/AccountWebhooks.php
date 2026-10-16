<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;

/**
 * The verifier and the mapper for the webhooks of one payment account.
 *
 * Built per account by the driver of its provider: the verifier checks against the account's webhook secret,
 * and a mapper that reads back from the provider does so with the account's own client.
 */
final readonly class AccountWebhooks
{
    public function __construct(
        public WebhookVerifier $verifier,
        public WebhookEventMapper $mapper,
    ) {}
}
