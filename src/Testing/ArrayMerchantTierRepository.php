<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Pushery\Billing\Contracts\MerchantTierRepository;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\MerchantTierRow;

/**
 * An in-memory merchant tier repository, so a consumer can test the marketplace catalogs without standing up
 * their `creator_tiers` table.
 *
 * It exists for fake PARITY: a diverging in-memory double is exactly what would let a cross-merchant
 * price-bleed pass a test and reach production, so this reads a merchant's tiers by the SAME scope uid the
 * database implementation keys on. Define a merchant's tiers with `define()`, then hand it to
 * DatabaseMerchantCatalog in place of the host repository.
 */
final class ArrayMerchantTierRepository implements MerchantTierRepository
{
    /** @var array<string, array<string, MerchantTierRow>> keyed by merchant uid, then by tier key */
    private array $rows = [];

    /** Define one merchant's tiers. Later calls for the same merchant add to it. */
    public function define(MerchantScope $scope, MerchantTierRow ...$tiers): self
    {
        foreach ($tiers as $tier) {
            $this->rows[$scope->uid()][$tier->key] = $tier;
        }

        return $this;
    }

    public function tiersFor(MerchantScope $scope): array
    {
        return $this->rows[$scope->uid()] ?? [];
    }

    public function tierRow(MerchantScope $scope, string $key): ?MerchantTierRow
    {
        return $this->rows[$scope->uid()][$key] ?? null;
    }
}
