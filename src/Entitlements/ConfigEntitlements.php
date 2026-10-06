<?php

declare(strict_types=1);

namespace Pushery\Billing\Entitlements;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\Entitlements;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\ValueObjects\MeteredDimension;
use Pushery\Billing\ValueObjects\Money;

/**
 * A config-backed Entitlements view of a tier, so a consumer that has no tier enum of its own can
 * describe and compare tiers straight from config('billing.tiers'). Identity/label/price/byok defer
 * to the TierCatalog; the metered dimensions are read from the tier's `dimensions` as definitions
 * (used = 0) for the pricing view; the upgrade ranking is the configured tier order. Anything
 * malformed degrades to a safe default rather than throwing — a tier absent from the ranking simply
 * ranks below every configured one.
 *
 * A tier's `dimensions` take the two forms a config can give them: the documented one, a list of keys
 * whose definitions stand under `billing.dimensions`, and a map of definitions on the tier itself.
 */
final readonly class ConfigEntitlements implements Entitlements
{
    /** @param list<string> $ranking tier keys in configured (upgrade-ranking) order, low to high */
    public function __construct(
        private string $tierKey,
        private TierCatalog $catalog,
        private Repository $config,
        private array $ranking,
    ) {}

    public function tierKey(): string
    {
        return $this->tierKey;
    }

    public function label(): string
    {
        return $this->catalog->label($this->tierKey);
    }

    public function priceDisplay(): ?Money
    {
        return $this->catalog->priceDisplay($this->tierKey);
    }

    public function isByok(): bool
    {
        return $this->catalog->isByok($this->tierKey);
    }

    public function dimensions(): array
    {
        $tiers = $this->config->get('billing.tiers');
        $tier = is_array($tiers) ? ($tiers[$this->tierKey] ?? null) : null;
        $dimensions = is_array($tier) ? ($tier['dimensions'] ?? null) : null;

        if (! is_array($dimensions)) {
            return [];
        }

        $defined = $this->config->get('billing.dimensions');
        $defined = is_array($defined) ? $defined : [];

        $out = [];

        foreach ($dimensions as $key => $spec) {
            // A key names a dimension defined under `billing.dimensions`, read as a map so a key is matched
            // literally rather than split on a dot. One with no definition is left out; the config validator
            // refuses it at boot.
            if (is_string($spec)) {
                $definition = $defined[$spec] ?? null;

                if (is_array($definition)) {
                    $out[] = $this->dimension($spec, $definition);
                }

                continue;
            }

            if (is_array($spec)) {
                $out[] = $this->dimension((string) $key, $spec);
            }
        }

        return $out;
    }

    public function isUpgradeOver(Entitlements $other): bool
    {
        return $this->rank($this->tierKey) > $this->rank($other->tierKey());
    }

    /** @param array<array-key, mixed> $spec */
    private function dimension(string $key, array $spec): MeteredDimension
    {
        $specKey = $spec['key'] ?? null;
        $label = $spec['label'] ?? null;
        $limit = $spec['limit'] ?? null;
        $unit = $spec['unit'] ?? null;
        $period = $spec['period'] ?? null;
        // `warn_threshold`, as the warning point is called everywhere else; `warn` is still read where a tier's
        // own definition was written with it.
        $warn = $spec['warn_threshold'] ?? $spec['warn'] ?? null;
        $policy = $spec['policy'] ?? null;

        return new MeteredDimension(
            key: is_string($specKey) ? $specKey : $key,
            label: is_string($label) ? $label : $key,
            used: 0,
            limit: ConfigLicense::ceiling($limit),
            unit: is_string($unit) ? $unit : '',
            period: is_string($period) ? $period : 'month',
            warnThreshold: is_int($warn) || is_float($warn) ? (float) $warn : 0.8,
            policy: is_string($policy) ? MeteringPolicy::tryFrom($policy) ?? MeteringPolicy::HardStop : MeteringPolicy::HardStop,
        );
    }

    private function rank(string $key): int
    {
        $position = array_search($key, $this->ranking, true);

        return $position === false ? -1 : $position;
    }
}
