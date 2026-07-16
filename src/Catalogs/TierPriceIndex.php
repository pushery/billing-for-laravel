<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;

/**
 * The reverse of the anti-price-injection map: given a provider price id, which configured tier does
 * it belong to? A subscription may carry prices the catalog does not list — a metered component, an
 * item the host app added itself — so "not a tier price" is a normal answer, never an error.
 *
 * A tier is recognized by its CURRENT price and by every price listed under its `legacy_prices`. Raising
 * a tier's price would otherwise turn every existing subscriber's tier unknown at the next webhook: they
 * are still on the old price id, and the tier they are paying for would stop being resolvable.
 */
final readonly class TierPriceIndex
{
    public function __construct(
        private TierCatalog $tiers,
        private PlanCatalog $plans,
    ) {}

    /** The tier key a provider price id maps to, or null when the price is not a configured tier price. */
    public function tierForPrice(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        foreach (array_keys($this->tiers->all()) as $key) {
            if ($this->plans->providerPriceFor($key) === $priceId) {
                return $key;
            }

            if (in_array($priceId, $this->plans->legacyPricesFor($key), true)) {
                return $key;
            }
        }

        return null;
    }

    public function isTierPrice(?string $priceId): bool
    {
        return $this->tierForPrice($priceId) !== null;
    }
}
