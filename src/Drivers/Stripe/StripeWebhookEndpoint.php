<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use LogicException;
use SensitiveParameter;

/**
 * The webhook endpoint of a Stripe payment account, as Stripe created it: its id, to remove it again, and its
 * signing secret, which Stripe hands out only at creation.
 *
 * The application stores the secret, encrypted, as the account's `webhookSecret`. The secret never shows in a
 * debugger, and the object refuses to be serialized.
 */
final readonly class StripeWebhookEndpoint
{
    public function __construct(
        public string $id,
        #[SensitiveParameter]
        public string $secret,
    ) {}

    /**
     * What a debugger shows: the id, never the secret.
     *
     * @return array{id: string, secret: string}
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'secret' => '(hidden)'];
    }

    /**
     * Refused: a serialized secret would reach a queue, a cache or a session in the clear.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException('A StripeWebhookEndpoint is never serialized. Store its id, and its secret encrypted.');
    }

    /**
     * Refused as well.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A StripeWebhookEndpoint is never unserialized.');
    }
}
