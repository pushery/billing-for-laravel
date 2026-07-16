<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The purchasable-plan catalog and the anti-price-injection guarantee: the client submits a tier
 * KEY, never a price id, and the server resolves the price from this allowlist. A plan is a
 * first-class local concept that MAY map to a provider price — never assume "plan == provider price".
 */
interface PlanCatalog
{
    /** The plan for a tier key, or null when the key is not purchasable. */
    public function planFor(string $tierKey): ?Plan;

    /** The remote provider price id a tier key maps to (e.g. a Stripe price id), or null. */
    public function providerPriceFor(string $tierKey): ?string;

    /**
     * Retired provider price ids that still resolve to this tier — a price rotated in the provider, a
     * grandfathered cohort. A subscription still on one of these is still on the tier. Never a price a
     * NEW subscription may be sold at (that is always providerPriceFor()): recognition, not sale.
     *
     * @return list<string>
     */
    public function legacyPricesFor(string $tierKey): array;

    /** @return list<Plan> the plans purchasable from the current tier (the upgrade/downgrade options). */
    public function options(TierIdentity $current): array;
}
