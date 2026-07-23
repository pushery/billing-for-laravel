<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The driver-shaped payload a front-end payment element needs to collect or confirm a payment
 * method — a Stripe SetupIntent client secret, or another provider's token or session blob.
 * The account hub hands this to the matching per-driver client adapter without knowing its shape,
 * plus a flag for whether the captured method can later be charged off-session (MIT).
 */
final readonly class ClientIntent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $driver,
        public array $payload,
        public bool $offSessionCapable = false,
    ) {}
}
