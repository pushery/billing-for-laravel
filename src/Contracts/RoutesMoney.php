<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * An OPTIONAL capability a {@see BillingDriver} may also implement to declare that it can route money
 * to a merchant other than the platform.
 *
 * Why this is an interface a driver opts into rather than a fifth method on BillingDriver: that
 * interface is implemented outside this package — a consumer registers its own driver through the
 * public BillingManager::extend() — so adding a method to it is a fatal error in code we do not own.
 * Here the type system carries the answer instead: a driver either is a RoutesMoney or it is not, and
 * asking one that is not never compiles into a half-configured marketplace.
 *
 * The useful consequence is that the marketplace path is physically unreachable, not merely switched
 * off. With billing.enabled off the manager resolves the NullDriver, which does not implement this
 * interface, so no amount of marketplace configuration can produce a rails object.
 */
interface RoutesMoney
{
    public function marketplaceRails(): MarketplaceRails;
}
