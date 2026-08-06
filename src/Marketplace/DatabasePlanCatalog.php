<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\MerchantTierRepository;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\MerchantTierRow;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * A merchant's purchasable-plan catalog, read from the host's rows.
 *
 * It implements the SAME PlanCatalog every caller already holds, with the merchant fixed at construction, and
 * it keeps the anti-price-injection guarantee per merchant: the price for a tier KEY comes ONLY from that
 * merchant's own row, never from config, never from another merchant, and never from anything the client
 * submitted. A key the merchant has not defined is not purchasable and resolves to null.
 */
final readonly class DatabasePlanCatalog implements PlanCatalog
{
    public function __construct(
        private MerchantTierRepository $repository,
        private MerchantScope $scope,
    ) {}

    public function planFor(string $tierKey): ?Plan
    {
        return $this->repository->tierRow($this->scope, $tierKey)?->plan();
    }

    public function providerPriceFor(string $tierKey): ?string
    {
        return $this->repository->tierRow($this->scope, $tierKey)?->providerPrice;
    }

    public function legacyPricesFor(string $tierKey): array
    {
        $row = $this->repository->tierRow($this->scope, $tierKey);

        return $row instanceof MerchantTierRow ? $row->legacyPrices : [];
    }

    public function options(TierIdentity $current): array
    {
        $out = [];

        foreach ($this->repository->tiersFor($this->scope) as $key => $row) {
            // An untouchable tier is a manually-granted plan the provider webhook must not overwrite — never
            // a self-service swap target — and the current tier is not an option to swap to itself.
            if ($key === $current->key) {
                continue;
            }
            if ($row->untouchable) {
                continue;
            }
            $out[] = $row->plan();
        }

        return $out;
    }
}
