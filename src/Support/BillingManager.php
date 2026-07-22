<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Drivers\NullDriver;
use Pushery\Billing\Exceptions\UnsupportedDriver;
use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * Resolves the active payment driver by name and honors the master switch: when billing.enabled is
 * off it returns the NullDriver, so a clone without billing has a clean no-op facade. Drivers are
 * registered lazily via extend() (the Stripe/Mollie/Adyen service providers register their own).
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

    private function defaultDriver(): string
    {
        $default = $this->config->get('billing.default', 'stripe');

        return is_string($default) ? $default : 'stripe';
    }
}
