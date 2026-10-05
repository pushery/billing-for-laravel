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
 * platform's own — the marketplace still has a platform. The whole merchant dimension is contained here; the
 * tier and plan catalogs it returns are the unchanged contracts every caller already speaks.
 *
 * ## The platform's tiers
 *
 * A platform sale is priced from the bound tier and plan catalogs, which read `billing.tiers`: the checkout asks
 * them for a sale with no merchant. So the platform scope answers from the same catalogs first, and from the
 * platform's rows for any key they do not declare ({@see PlatformTierCatalog}). Answering from the rows alone
 * left every reader on the other side of the sale (the webhook, a swap, the revenue report) without a tier for a
 * price the checkout had just charged.
 *
 * The container hands both catalogs over. Constructed by hand without them, the platform scope reads its rows
 * only, as before.
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
     *
     * @param  ?TierCatalog  $platformTiers  the tier catalog a platform sale is priced from, the bound one
     * @param  ?PlanCatalog  $platformPlans  the plan catalog a platform sale is priced from, the bound one
     */
    public function __construct(
        MerchantTierRepository $repository,
        private ?TierCatalog $platformTiers = null,
        private ?PlanCatalog $platformPlans = null,
    ) {
        // Wrapped unconditionally. An instanceof check to avoid double-wrapping would be a branch nothing
        // ever takes — a memo around a memo simply delegates — and an untaken branch in a published package
        // is a claim about a situation nobody has.
        $this->repository = new MemoizedMerchantTierRepository($repository);
    }

    public function tierCatalog(?MerchantScope $scope = null): TierCatalog
    {
        $scope ??= MerchantScope::platform();
        $rows = new DatabaseTierCatalog($this->repository, $scope);

        if (! $scope->isPlatform() || ! $this->platformTiers instanceof TierCatalog || ! $this->platformPlans instanceof PlanCatalog) {
            return $rows;
        }

        return new PlatformTierCatalog($this->platformTiers, $rows);
    }

    public function planCatalog(?MerchantScope $scope = null): PlanCatalog
    {
        $scope ??= MerchantScope::platform();
        $rows = new DatabasePlanCatalog($this->repository, $scope);

        // Both configured catalogs or neither: a tier catalog that knows a configured key beside a plan catalog
        // that cannot price it would name a tier nothing can sell.
        if (! $scope->isPlatform() || ! $this->platformTiers instanceof TierCatalog || ! $this->platformPlans instanceof PlanCatalog) {
            return $rows;
        }

        return new PlatformPlanCatalog($this->platformTiers, $this->platformPlans, $rows, $this->tierCatalog($scope));
    }
}
