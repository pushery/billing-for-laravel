<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\MerchantTierRepository;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\MerchantTierRow;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * A merchant's tier catalog, read from the host's rows rather than from config.
 *
 * It implements the SAME TierCatalog every caller already holds — the merchant is fixed at construction, so
 * downstream code that asks a catalog about a tier key never learns it is per merchant. A key the merchant
 * has not defined answers exactly as an unknown config key does: no identity, no price, the zero-tier
 * fail-safe upstream. It never falls back to config or to another merchant — a tier is this merchant's or it
 * is nobody's.
 */
final readonly class DatabaseTierCatalog implements TierCatalog
{
    public function __construct(
        private MerchantTierRepository $repository,
        private MerchantScope $scope,
    ) {}

    public function all(): array
    {
        $out = [];
        $level = 0;

        foreach ($this->repository->tiersFor($this->scope) as $key => $row) {
            $out[$key] = $row->identity($level);
            $level++;
        }

        return $out;
    }

    public function find(string $key): ?TierIdentity
    {
        return $this->repository->tierRow($this->scope, $key)?->identity($this->level($key));
    }

    public function label(string $key): string
    {
        $row = $this->repository->tierRow($this->scope, $key);

        return $row instanceof MerchantTierRow ? $row->label : $key;
    }

    public function isByok(string $key): bool
    {
        return $this->repository->tierRow($this->scope, $key)?->byok === true;
    }

    public function isUntouchable(string $key): bool
    {
        return $this->repository->tierRow($this->scope, $key)?->untouchable === true;
    }

    public function priceDisplay(string $key): ?Money
    {
        return $this->repository->tierRow($this->scope, $key)?->priceDisplay();
    }

    public function level(string $key): int
    {
        $index = array_search($key, array_keys($this->repository->tiersFor($this->scope)), true);

        // An unknown key ranks below every real tier, so a cumulative "at least" check can never pass on it.
        return $index === false ? -1 : $index;
    }
}
