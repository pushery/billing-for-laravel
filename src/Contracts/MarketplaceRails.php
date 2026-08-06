<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The marketplace sibling of {@see PaymentRails}: everything a driver must supply before money can be
 * destined to somebody other than the platform.
 *
 * It is a SEPARATE contract rather than methods added to PaymentRails, and that is the load-bearing
 * decision of the whole marketplace layer. PaymentRails has implementations outside this repository —
 * a consumer registers its own driver through the public BillingManager::extend() — so a method added
 * there is a fatal error in code we do not own. A sibling contract a driver may also implement breaks
 * nobody: a driver that does not route money simply does not implement it, and the marketplace path is
 * then unreachable rather than half-present.
 *
 * A driver announces that it implements this by implementing {@see RoutesMoney}.
 */
interface MarketplaceRails
{
    /** Give a merchant a provider account and drive the provider's hosted identity flow. */
    public function onboarding(): MerchantOnboarding;

    /** Resolve merchants to provider accounts and back. */
    public function accounts(): MerchantAccountDirectory;
}
