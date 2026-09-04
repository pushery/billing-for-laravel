<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\MarketplaceRails;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Drivers\NullDriver;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\Exceptions\UnsupportedDriver;
use Pushery\Billing\ValueObjects\DriverCapabilities;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Resolves the active payment driver by name and honors the master switch: when billing.enabled is
 * off it returns the NullDriver, so a clone without billing has a clean no-op facade. Drivers are
 * registered lazily via extend() (the driver service providers register their own).
 */
final class BillingManager
{
    /** @var array<string, callable(): BillingDriver> */
    private array $factories = [];

    public function __construct(private readonly Repository $config) {}

    /** Register a driver factory under a name (e.g. "stripe"). */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /** Whether billing is enabled (the master switch). */
    public function enabled(): bool
    {
        return (bool) $this->config->get('billing.enabled', true);
    }

    /**
     * The active driver — the NullDriver when billing is disabled, otherwise the named/default driver.
     *
     * ## It takes a NAME, and deliberately not an owner
     *
     * There is no per-owner driver resolution in this package, and that is a decision rather than a gap.
     * One install runs one provider; changing providers is a migration of `billing.default`, not a mixed
     * mode. Two things follow from it, and both are load-bearing:
     *
     * A marketplace does NOT need per-owner drivers. Its sellers are merchants at the SAME provider,
     * scoped by {@see MerchantScope} — a creator's subscription is
     * charged through the platform's driver against the creator's own connected account, not through a
     * different provider.
     *
     * And the seams that a screen resolves — `WebhookVerifier`, `WebhookEventMapper`, `PaymentMethods`,
     * `PaymentCsp`, `Invoices`, `SubscriptionActions`, `ProrationStrategy` — are bound ONCE at boot, by
     * the active driver's service provider. A screen resolving one of those gets the active driver's
     * implementation whatever row it is rendering. Making that owner-dependent would mean routing every
     * resolution through this manager, and the contracts would stop being container bindings — which is
     * exactly what a consumer overrides today to swap one behavior without forking a driver.
     *
     * So a caller passing a name that is not the default gets that driver's RAILS, ENGINE and
     * CAPABILITIES — the three things this manager owns — and the boot-bound seams stay as they are.
     * Anything else would be half a mechanism, and half a mechanism here means a subscriber silently
     * charged through the wrong provider.
     */
    public function driver(?string $name = null): BillingDriver
    {
        if (! $this->enabled()) {
            return new NullDriver;
        }

        $name ??= $this->defaultDriver();

        $factory = $this->factories[$name] ?? throw new UnsupportedDriver($name);

        return $factory();
    }

    public function capabilities(?string $name = null): DriverCapabilities
    {
        return $this->driver($name)->capabilities();
    }

    /** Whether the marketplace path is switched on (the single, authoritative key). */
    public function marketplaceEnabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.enabled', false);
    }

    /**
     * The driver's marketplace rails, or a loud failure.
     *
     * There is no null-returning variant on purpose. A caller that has reached this point intends to
     * route money; handing it null would move the decision to whatever dereferences the result, which
     * is a fatal error one layer further from the cause. The two refusals are told apart because their
     * fixes are different: billing off is a master-switch problem, a non-routing driver is a driver
     * choice.
     */
    public function marketplaceRails(?string $name = null): MarketplaceRails
    {
        if (! $this->enabled()) {
            throw MarketplaceUnsupported::billingDisabled();
        }

        $driver = $this->driver($name);

        if (! $driver instanceof RoutesMoney) {
            throw MarketplaceUnsupported::driverCannotRoute($driver->name());
        }

        return $driver->marketplaceRails();
    }

    private function defaultDriver(): string
    {
        $default = $this->config->get('billing.default', 'stripe');

        return is_string($default) ? $default : 'stripe';
    }
}
