<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The purchasable-plan catalog and the anti-price-injection guarantee: the client submits a tier
 * key, never a price id, and the server resolves the price from this allowlist. A plan is a
 * first-class local concept that may map to a provider price — never assume "plan == provider price".
 *
 * A tier may be sold at more than one interval under its one key, monthly and yearly for one. Its
 * entitlements, label and rank stay those of the tier whichever interval it is billed at; only the plan
 * differs. Without an interval every method answers for the tier's default interval, as it always did.
 */
interface PlanCatalog
{
    /** The plan for a tier key at an interval, or null when the key is not purchasable at it. */
    public function planFor(string $tierKey, ?BillingInterval $interval = null): ?Plan;

    /** The remote provider price id a tier key maps to at an interval (e.g. a Stripe price id), or null. */
    public function providerPriceFor(string $tierKey, ?BillingInterval $interval = null): ?string;

    /**
     * Retired provider price ids that still resolve to this tier — a price rotated in the provider, a
     * grandfathered cohort — at any of its intervals. A subscription still on one of these is still on the
     * tier. Never a price a new subscription may be sold at (that is always providerPriceFor()): recognition,
     * not sale.
     *
     * @return list<string>
     */
    public function legacyPricesFor(string $tierKey): array;

    /**
     * The intervals the tier is configured at, its default interval first. A key that is not a tier answers
     * the default interval alone or nothing, and planFor() decides whether it is sold at all.
     *
     * @return list<BillingInterval>
     */
    public function intervalsFor(string $tierKey): array;

    /** @return list<Plan> the plans purchasable from the current tier (the upgrade/downgrade options). */
    public function options(TierIdentity $current): array;
}
