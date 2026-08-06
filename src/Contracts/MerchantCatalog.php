<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The one seam that takes a merchant. It answers "the tier and plan catalogs FOR this merchant", so a
 * marketplace's creators each own their tiers and prices while everything downstream keeps using the
 * unchanged TierCatalog and PlanCatalog it hands back.
 *
 * The merchant dimension lives HERE and nowhere else: no existing catalog signature moves, so every caller
 * that already holds a TierCatalog or a PlanCatalog is untouched. The default binding returns the
 * config-bound catalogs for ANY scope, so a single-seller install is byte-for-byte unchanged and never
 * consults a merchant; a marketplace rebinds this to a database-backed implementation that reads each
 * merchant's own rows. The anti-price-injection guarantee is identical in both modes — a tier KEY resolves
 * only to a price the merchant's own catalog declares, never one the client submitted.
 */
interface MerchantCatalog
{
    public function tierCatalog(?MerchantScope $scope = null): TierCatalog;

    public function planCatalog(?MerchantScope $scope = null): PlanCatalog;
}
