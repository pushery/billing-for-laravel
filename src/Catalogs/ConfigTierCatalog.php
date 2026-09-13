<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The config-driven tier catalog: reads config('billing.tiers') (an ordered map keyed by tier key)
 * and answers identity/label/price-display questions. The configured order is the upgrade ranking.
 */
final readonly class ConfigTierCatalog implements SuppliesProductArchetypes, TierCatalog
{
    public function __construct(private Repository $config) {}

    /**
     * What kind of subscription this tier sells, from `billing.tiers.<key>.archetype`.
     *
     * An UNSET key answers null, and the withdrawal resolver reads that as a plain subscription, which is what a
     * tier is. An unreadable value REFUSES for the reason the add-on catalog gives: a typo resolved to null would
     * hand the caller a classification somebody believed they had changed.
     */
    public function archetypeFor(string $key): ?TaxArchetype
    {
        $archetype = $this->config->get("billing.tiers.{$key}.archetype");

        if ($archetype === null) {
            return null;
        }

        $resolved = is_string($archetype) ? TaxArchetype::tryFrom($archetype) : null;

        if (! $resolved instanceof TaxArchetype) {
            throw new InvalidArgumentException(
                "Tier '{$key}': archetype must be one of "
                .implode(', ', array_map(static fn (TaxArchetype $case): string => $case->value, TaxArchetype::cases()))
                .'.'
            );
        }

        return $resolved;
    }

    public function all(): array
    {
        $tiers = $this->config->get('billing.tiers');

        if (! is_array($tiers)) {
            return [];
        }

        $out = [];

        foreach (array_keys($tiers) as $key) {
            $key = (string) $key;
            $out[$key] = $this->identity($key);
        }

        return $out;
    }

    public function find(string $key): ?TierIdentity
    {
        return is_array($this->config->get("billing.tiers.{$key}")) ? $this->identity($key) : null;
    }

    public function label(string $key): string
    {
        $label = $this->config->get("billing.tiers.{$key}.label");

        return is_string($label) ? $label : $key;
    }

    public function isByok(string $key): bool
    {
        return $this->config->get("billing.tiers.{$key}.byok") === true;
    }

    public function isUntouchable(string $key): bool
    {
        return $this->config->get("billing.tiers.{$key}.untouchable") === true;
    }

    public function priceDisplay(string $key): ?Money
    {
        $amount = $this->config->get("billing.tiers.{$key}.price_display.amount");
        $currency = $this->config->get("billing.tiers.{$key}.price_display.currency");

        return is_int($amount) && is_string($currency) ? Money::of($amount, $currency) : null;
    }

    public function level(string $key): int
    {
        $tiers = $this->config->get('billing.tiers');

        if (! is_array($tiers)) {
            return -1;
        }

        $index = array_search($key, array_map(strval(...), array_keys($tiers)), true);

        // An unknown key ranks below every real tier, so a cumulative "at least" comparison against it can
        // never pass by accident.
        return $index === false ? -1 : $index;
    }

    private function identity(string $key): TierIdentity
    {
        return new TierIdentity(
            key: $key,
            label: $this->label($key),
            byok: $this->isByok($key),
            untouchable: $this->isUntouchable($key),
            level: $this->level($key),
        );
    }
}
