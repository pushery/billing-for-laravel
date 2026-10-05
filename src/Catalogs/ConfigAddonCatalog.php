<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\ClassifiesMoneyCredit;
use Pushery\Billing\Contracts\SuppliesBuyerAudiences;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\BuyerAudience;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * The config-driven add-on catalog (config('billing.addons')). One-time purchases resolve their
 * price here from the add-on KEY — the client never submits a price, mirroring the tier allowlist.
 */
final readonly class ConfigAddonCatalog implements AddonCatalog, ClassifiesMoneyCredit, SuppliesBuyerAudiences, SuppliesProductArchetypes
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

    /**
     * Whether the key names an add-on of the catalog.
     *
     * Read off the key set rather than through `billing.addons.{$key}`: the repository resolves a dot as nesting,
     * so a sub-map of an add-on, such as `credits.price_display`, would answer as an add-on of its own.
     */
    public function exists(string $key): bool
    {
        $addons = $this->config->get('billing.addons');

        return is_array($addons) && is_array($addons[$key] ?? null);
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
     * Whether this configured add-on credits money: one that grants no units and that is either unclassified, the
     * default `billing.addons` documents, or classified as a voucher. An add-on with any other archetype is a
     * product, and a key the configuration does not name credits nothing.
     */
    public function isMoneyCredit(string $key): bool
    {
        if (! $this->exists($key) || $this->grantsFor($key) instanceof UnitGrant) {
            return false;
        }

        $archetype = $this->archetypeFor($key);

        return ! $archetype instanceof TaxArchetype || $archetype === TaxArchetype::Voucher;
    }

    /**
     * The usage units this add-on grants, or null when it grants none: money credit or a product, which
     * {@see self::isMoneyCredit()} tells apart.
     *
     * `billing.addons.<key>.grants = ['meter' => 'emails', 'units' => 1000]`. A malformed grant is refused
     * rather than read as no grant or as a zero-unit one: an add-on that charges the customer and hands them
     * nothing is the one outcome worth being loud about. A value that is not an array is malformed too, since
     * read as no grant it credits money in place of the units. The boot validator refuses the same shapes, so a
     * malformed grant stops the application before anybody can pay for it.
     */
    public function grantsFor(string $key): ?UnitGrant
    {
        $grant = $this->config->get("billing.addons.{$key}.grants");

        if ($grant === null) {
            return null;
        }

        $meter = is_array($grant) ? ($grant['meter'] ?? null) : null;
        $units = is_array($grant) ? ($grant['units'] ?? null) : null;

        if (! is_string($meter) || $meter === '' || ! is_int($units) || $units <= 0) {
            throw new InvalidArgumentException("Add-on '{$key}': grants must be {meter: string, units: positive int}.");
        }

        return new UnitGrant($meter, $units);
    }

    /** Who may buy the offer, from `billing.addons.<key>.buyers`: anyone unless it says `business`. */
    public function audienceFor(string $key): BuyerAudience
    {
        return BuyerAudience::fromConfig($this->config->get("billing.addons.{$key}.buyers"), "billing.addons.{$key}");
    }

    /**
     * What kind of thing this add-on is, from `billing.addons.<key>.archetype`.
     *
     * An UNSET key answers null, and an unreadable one REFUSES. The two are different situations: nobody
     * having classified an add-on yet is an ordinary state on an install that does not use the classification
     * at all, while a value that is not one of the archetypes is a typo — and resolving a typo to null
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
