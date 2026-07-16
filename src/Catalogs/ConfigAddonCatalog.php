<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * The config-driven add-on catalog (config('billing.addons')). One-time purchases resolve their
 * price here from the add-on KEY — the client never submits a price, mirroring the tier allowlist.
 */
final readonly class ConfigAddonCatalog
{
    public function __construct(
        private Repository $config,
        private ProviderPriceResolver $prices,
    ) {}

    /** @return list<string> the configured add-on keys, in order. */
    public function all(): array
    {
        $addons = $this->config->get('billing.addons');

        return is_array($addons) ? array_map(strval(...), array_keys($addons)) : [];
    }

    public function exists(string $key): bool
    {
        return is_array($this->config->get("billing.addons.{$key}"));
    }

    public function label(string $key): string
    {
        $label = $this->config->get("billing.addons.{$key}.label");

        return is_string($label) ? $label : $key;
    }

    public function priceFor(string $key): ?Money
    {
        $amount = $this->config->get("billing.addons.{$key}.price_display.amount");
        $currency = $this->config->get("billing.addons.{$key}.price_display.currency");

        return is_int($amount) && is_string($currency) ? Money::of($amount, $currency) : null;
    }

    public function providerPriceFor(string $key): ?string
    {
        // Supports a per-provider price map as well as a scalar; the add-on KEY is the client's input, the
        // price is resolved from config (anti-price-injection).
        return $this->prices->forAddon($key);
    }

    /**
     * The usage units this add-on grants, or null when it grants money credit instead.
     *
     * `billing.addons.<key>.grants = ['meter' => 'emails', 'units' => 1000]`. A malformed grant resolves to
     * null rather than to a silent zero-unit grant — an add-on that charges the customer and hands them
     * nothing is the one outcome worth being loud about, and the boot-time config test catches it.
     */
    public function grantsFor(string $key): ?UnitGrant
    {
        $grant = $this->config->get("billing.addons.{$key}.grants");

        if (! is_array($grant)) {
            return null;
        }

        $meter = $grant['meter'] ?? null;
        $units = $grant['units'] ?? null;

        if (! is_string($meter) || $meter === '' || ! is_int($units) || $units <= 0) {
            throw new InvalidArgumentException("Add-on '{$key}': grants must be {meter: string, units: positive int}.");
        }

        return new UnitGrant($meter, $units);
    }
}
