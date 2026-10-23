<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The licensing gate: what a tier unlocks, kept separate from what it costs (the billing config). An
 * app resolves the owner's tier key (via its TierResolver) and asks here whether a boolean feature is
 * granted or what a numeric limit is. Denials fail closed — an unlisted feature is not granted — while
 * an unlisted or null limit means "not capped".
 */
interface License
{
    /** Whether the tier unlocks a boolean feature (false when the tier or feature is not listed). */
    public function grants(string $tierKey, string $feature): bool;

    /** The tier's numeric ceiling for a key, or null when unlimited / not configured. */
    public function limit(string $tierKey, string $key): ?int;
}
