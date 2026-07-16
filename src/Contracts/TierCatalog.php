<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The catalog of configured tiers, read from config. Answers identity and display questions about
 * a tier key without touching a provider.
 */
interface TierCatalog
{
    /** @return array<string,TierIdentity> keyed by tier key, in configured (upgrade-ranking) order. */
    public function all(): array;

    public function find(string $key): ?TierIdentity;

    public function label(string $key): string;

    public function isByok(string $key): bool;

    public function isUntouchable(string $key): bool;

    /** The static catalog price to display for a tier, or null when it has none (e.g. the free tier). */
    public function priceDisplay(string $key): ?Money;
}
