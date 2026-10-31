<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingInterval;

/**
 * The reverse of the anti-price-injection map: given a provider price id, which configured tier does
 * it belong to? A subscription may carry prices the catalog does not list — a metered component, an
 * item the host app added itself — so "not a tier price" is a normal answer, never an error.
 *
 * A tier is recognized by its current price and by every price listed under its `legacy_prices`. Raising
 * a tier's price would otherwise turn every existing subscriber's tier unknown at the next webhook: they
 * are still on the old price id, and the tier they are paying for would stop being resolvable.
 */
final readonly class TierPriceIndex
{
    public function __construct(
        private TierCatalog $tiers,
        private PlanCatalog $plans,
    ) {}

    /**
     * The tier key a provider price id maps to, or null when the price is not a configured tier price. A price of
     * any interval the tier is sold at maps to the one tier, so the entitlements do not depend on the interval.
     */
    public function tierForPrice(?string $priceId): ?string
    {
        return $this->match($priceId)['tier'] ?? null;
    }

    /**
     * The interval a provider price id sells its tier at, or null when the price is no current tier price. A legacy
     * price answers null: it names its tier, and the catalog does not say at which interval it was sold.
     */
    public function intervalForPrice(?string $priceId): ?BillingInterval
    {
        return $this->match($priceId)['interval'] ?? null;
    }

    /** @return array{tier: string, interval: ?BillingInterval}|null */
    private function match(?string $priceId): ?array
    {
        if ($priceId === null) {
            return null;
        }

        foreach (array_keys($this->tiers->all()) as $key) {
            foreach ($this->plans->intervalsFor($key) as $interval) {
                if ($this->plans->providerPriceFor($key, $interval) === $priceId) {
                    return ['tier' => $key, 'interval' => $interval];
                }
            }

            if ($this->plans->providerPriceFor($key) === $priceId || in_array($priceId, $this->plans->legacyPricesFor($key), true)) {
                return ['tier' => $key, 'interval' => null];
            }
        }

        return null;
    }

    public function isTierPrice(?string $priceId): bool
    {
        return $this->tierForPrice($priceId) !== null;
    }
}
