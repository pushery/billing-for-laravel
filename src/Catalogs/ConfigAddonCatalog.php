<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * The config-driven add-on catalog (config('billing.addons')). One-time purchases resolve their
 * price here from the add-on KEY — the client never submits a price, mirroring the tier allowlist.
 */
final readonly class ConfigAddonCatalog implements AddonCatalog, SuppliesProductArchetypes
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

    /**
     * What kind of thing this add-on is, from `billing.addons.<key>.archetype`.
     *
     * An UNSET key answers null, and an unreadable one REFUSES. The two are different situations: nobody
     * having classified an add-on yet is an ordinary state on an install that does not use the classification
     * at all, while a value that is not one of the nine archetypes is a typo — and resolving a typo to null
     * would hand the caller "unclassified" for a product somebody believed they had classified.
     */
    public function archetypeFor(string $key): ?TaxArchetype
    {
        $archetype = $this->config->get("billing.addons.{$key}.archetype");

        if ($archetype === null) {
            return null;
        }

        $resolved = is_string($archetype) ? TaxArchetype::tryFrom($archetype) : null;

        if (! $resolved instanceof TaxArchetype) {
            throw new InvalidArgumentException(
                "Add-on '{$key}': archetype must be one of "
                .implode(', ', array_map(static fn (TaxArchetype $case): string => $case->value, TaxArchetype::cases()))
                .'.'
            );
        }

        return $resolved;
    }
}
