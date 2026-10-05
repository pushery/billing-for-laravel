<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The plans behind the platform's own tiers on a marketplace, beside {@see PlatformTierCatalog}: a key the
 * configuration declares is priced from the configured plan catalog, the one the checkout charges from, and any
 * other key from the platform's rows.
 *
 * The reverse lookup from a provider price to a tier walks the tier keys and asks this catalog for each one's
 * price, so the price a platform sale was charged under resolves to its tier again, and a merchant's price never
 * does: the rows read here are the platform's only.
 */
final readonly class PlatformPlanCatalog implements PlanCatalog
{
    public function __construct(
        private TierCatalog $configuredTiers,
        private PlanCatalog $configured,
        private PlanCatalog $rows,
        private TierCatalog $tiers,
    ) {}

    public function planFor(string $tierKey): ?Plan
    {
        return $this->declaring($tierKey)->planFor($tierKey);
    }

    public function providerPriceFor(string $tierKey): ?string
    {
        return $this->declaring($tierKey)->providerPriceFor($tierKey);
    }

    public function legacyPricesFor(string $tierKey): array
    {
        return $this->declaring($tierKey)->legacyPricesFor($tierKey);
    }

    /**
     * Every other tier the platform sells, configured and rows alike, in the order the tier catalog ranks them.
     * An untouchable tier is never offered, and a key without a plan has nothing to offer.
     */
    public function options(TierIdentity $current): array
    {
        $out = [];

        foreach ($this->tiers->all() as $key => $identity) {
            if ($key === $current->key) {
                continue;
            }

            if ($identity->untouchable) {
                continue;
            }

            $plan = $this->planFor($key);

            if ($plan instanceof Plan) {
                $out[] = $plan;
            }
        }

        return $out;
    }

    /** The plan catalog that answers for this key: the configured one when the configuration declares the tier. */
    private function declaring(string $tierKey): PlanCatalog
    {
        return $this->configuredTiers->find($tierKey) instanceof TierIdentity ? $this->configured : $this->rows;
    }
}
