<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\MeteringPolicy;

/**
 * The outcome of a quota pre-check for one meter: whether the request is allowed, whether it should run
 * degraded (a degrade policy past the allowance still serves, on a cheaper path), and how much allowance
 * is left. `blocked()` is the one thing a gate keys on — only a hard-stop / refuse policy past its
 * allowance is blocked; a degrade or fair-use meter is always allowed.
 *
 * With spend caps on, the owner's cap can block a request on any meter as well, and `remainingSpend` is what is left
 * under it. Null when spend caps are off.
 */
final readonly class QuotaDecision
{
    public function __construct(
        public string $meterKey,
        public MeteringPolicy $policy,
        public bool $allowed,
        public bool $degraded,
        public ?int $remaining,
        public ?Money $remainingSpend = null,
    ) {}

    public function blocked(): bool
    {
        return ! $this->allowed;
    }
}
