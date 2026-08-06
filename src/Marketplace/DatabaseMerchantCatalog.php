<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\MerchantTierRepository;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The merchant catalog a marketplace binds in place of the single-seller default: every scope resolves to a
 * database-backed catalog reading THAT merchant's rows.
 *
 * A fresh catalog is built per scope so two merchants never share one, and a null scope resolves to the
 * platform's own — the marketplace still has a platform, and its tiers are rows like any creator's. The whole
 * merchant dimension is contained here; the tier and plan catalogs it returns are the unchanged contracts
 * every caller already speaks.
 */
final readonly class DatabaseMerchantCatalog implements MerchantCatalog
{
    private MerchantTierRepository $repository;

    /**
     * The host's repository is wrapped so it is asked once per merchant, not once per tier key.
     *
     * The reverse price lookup walks a merchant's keys and asks for each one's price and legacy prices —
     * roughly 2N+1 reads for a single answer, on the webhook path. Wrapping here is what keeps the obvious
     * host implementation (a plain query) from turning every incoming event into a burst of identical ones.
     */
    public function __construct(MerchantTierRepository $repository)
    {
        // Wrapped unconditionally. An instanceof check to avoid double-wrapping would be a branch nothing
        // ever takes — a memo around a memo simply delegates — and an untaken branch in a published package
        // is a claim about a situation nobody has.
        $this->repository = new MemoizedMerchantTierRepository($repository);
    }

    public function tierCatalog(?MerchantScope $scope = null): TierCatalog
    {
        return new DatabaseTierCatalog($this->repository, $scope ?? MerchantScope::platform());
    }

    public function planCatalog(?MerchantScope $scope = null): PlanCatalog
    {
        return new DatabasePlanCatalog($this->repository, $scope ?? MerchantScope::platform());
    }
}
