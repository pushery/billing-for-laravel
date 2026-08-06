<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The default merchant catalog for a single-seller install: it hands back the one config-bound tier and plan
 * catalog for ANY scope, merchant or platform alike.
 *
 * Returning the same instance regardless of scope is the whole point — a single-seller app has exactly one
 * catalog, so passing a merchant must change nothing. The scope is accepted to satisfy the seam and
 * deliberately unused; a marketplace binds a database-backed catalog in this one place instead, and nothing
 * downstream has to know which mode it is in.
 */
final readonly class SingleMerchantCatalog implements MerchantCatalog
{
    public function __construct(
        private TierCatalog $tiers,
        private PlanCatalog $plans,
    ) {}

    public function tierCatalog(?MerchantScope $scope = null): TierCatalog
    {
        return $this->tiers;
    }

    public function planCatalog(?MerchantScope $scope = null): PlanCatalog
    {
        return $this->plans;
    }
}
