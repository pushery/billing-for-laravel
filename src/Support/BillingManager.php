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

    /** The active driver — the NullDriver when billing is disabled, otherwise the named/default driver. */
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
