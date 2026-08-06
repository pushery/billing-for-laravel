<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Builds a reverse price index scoped to one merchant.
 *
 * The reverse lookup — which tier does this provider price belong to — has to run against the RIGHT
 * catalog, or a marketplace resolves a creator's price against the platform's tiers and a swap reprices the
 * wrong item. So the index is built per scope from that merchant's own tier and plan catalogs, and a null
 * scope builds it over the platform's, byte-for-byte what a single-seller install had before.
 */
final readonly class TierPriceIndexFactory
{
    public function __construct(private MerchantCatalog $catalogs) {}

    public function for(?MerchantScope $scope = null): TierPriceIndex
    {
        return new TierPriceIndex(
            $this->catalogs->tierCatalog($scope),
            $this->catalogs->planCatalog($scope),
        );
    }
}
