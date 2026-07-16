<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\Money;

/**
 * The usage-billed components a tier carries, read from `config('billing.tiers.<tier>.metered')`.
 * Same guarantee as the plan catalog: the client submits a METER key, never a price or a quantity to
 * charge for — both are resolved here.
 *
 * Malformed metering config throws rather than resolving to "not billable". A typo that silently
 * turned metered usage into free usage would be discovered on the month's invoice, which is the worst
 * possible place to discover it.
 */
final readonly class MeterCatalog
{
    public function __construct(private Repository $config) {}

    /** @return list<MeteredComponent> the tier's usage-billed components, in configured order. */
    public function forTier(string $tierKey): array
    {
        $metered = $this->config->get("billing.tiers.{$tierKey}.metered");

        if (! is_array($metered)) {
            return [];
        }

        $out = [];

        foreach ($metered as $key => $definition) {
            $out[] = $this->make($tierKey, (string) $key, $definition);
        }

        return $out;
    }

    /** One component of a tier by its meter key, or null when the tier does not meter it. */
    public function component(string $tierKey, string $meterKey): ?MeteredComponent
    {
        $definition = $this->config->get("billing.tiers.{$tierKey}.metered.{$meterKey}");

        return $definition === null ? null : $this->make($tierKey, $meterKey, $definition);
    }

    private function make(string $tierKey, string $meterKey, mixed $definition): MeteredComponent
    {
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Metered component '{$meterKey}' on tier '{$tierKey}' must be an array.");
        }

        $unitPrice = $this->money($definition['unit_price'] ?? null, $tierKey, $meterKey);

        return new MeteredComponent(
            key: $meterKey,
            label: $this->string($definition, 'label') ?? $meterKey,
            unit: $this->string($definition, 'unit') ?? 'unit',
            providerMeter: $this->string($definition, 'provider_meter'),
            providerPrice: $this->string($definition, 'provider_price'),
            packageSize: $this->packageSize($definition, $tierKey, $meterKey),
            unitPrice: $unitPrice,
            included: $this->included($definition, $tierKey, $meterKey),
            policy: $this->policy($definition, $tierKey, $meterKey),
            warnThreshold: $this->warnThreshold($definition, $tierKey, $meterKey),
        );
    }

    /**
     * The fraction of the allowance at which the customer is warned. 0.8 by default; a meter may set its
     * own. Validated here rather than trusted: a threshold outside 0..1 would either warn on the first
     * unit or never warn at all, and both fail silently.
     *
     * @param  array<array-key, mixed>  $definition
     */
    private function warnThreshold(array $definition, string $tierKey, string $meterKey): float
    {
        $threshold = $definition['warn_threshold'] ?? null;

        if ($threshold === null) {
            return 0.8;
        }

        if ((! is_float($threshold) && ! is_int($threshold)) || $threshold <= 0 || $threshold > 1) {
            throw new InvalidArgumentException("Metered component '{$meterKey}' on tier '{$tierKey}': warn_threshold must be between 0 and 1.");
        }

        return (float) $threshold;
    }

    /** @return list<string> every meter key configured on any tier (what a reporter may be asked for). */
    public function meterKeys(): array
    {
        $keys = [];

        foreach ($this->components() as $component) {
            $keys[$component->key] = true;
        }

        return array_map(strval(...), array_keys($keys));
    }

    /**
     * The provider meter (event name) of every component any tier actually BILLS for, unique and in
     * configured order — what `billing:meters:check` verifies exists at the provider. A component is
     * billable only when it has both a provider meter and a price, so every entry here is a non-empty
     * provider meter.
     *
     * @return list<string>
     */
    public function billableProviderMeters(): array
    {
        $meters = [];

        foreach ($this->components() as $component) {
            if ($component->isBillable() && $component->providerMeter !== null) {
                $meters[$component->providerMeter] = true;
            }
        }

        return array_map(strval(...), array_keys($meters));
    }

    /**
     * Every component any tier actually BILLS for, deduplicated by its provider meter — what
     * `billing:meters:check` verifies against the provider's meters AND prices. Two tiers billing the same
     * meter with the same price are one thing to check, not two.
     *
     * @return list<MeteredComponent>
     */
    public function billableComponents(): array
    {
        $out = [];

        foreach ($this->components() as $component) {
            if ($component->isBillable() && $component->providerMeter !== null) {
                $out[$component->providerMeter.'|'.$component->providerPrice] = $component;
            }
        }

        return array_values($out);
    }

    /**
     * The first meter key any tier actually BILLS for — a component with both a provider meter and a
     * price. Null when nothing is billed by usage, which is what tells the boot guard it has no work.
     */
    public function firstBillableMeter(): ?string
    {
        foreach ($this->components() as $component) {
            if ($component->isBillable()) {
                return $component->key;
            }
        }

        return null;
    }

    /** @return list<MeteredComponent> every metered component of every configured tier. */
    private function components(): array
    {
        $tiers = $this->config->get('billing.tiers');

        if (! is_array($tiers)) {
            return [];
        }

        $out = [];

        foreach (array_keys($tiers) as $tierKey) {
            foreach ($this->forTier((string) $tierKey) as $component) {
                $out[] = $component;
            }
        }

        return $out;
    }

    /** @param  array<array-key, mixed>  $definition */
    private function string(array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param  array<array-key, mixed>  $definition */
    private function packageSize(array $definition, string $tierKey, string $meterKey): int
    {
        $size = $definition['package_size'] ?? 1;

        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException("Metered component '{$meterKey}' on tier '{$tierKey}': package_size must be a positive integer.");
        }

        return $size;
    }

    /** @param  array<array-key, mixed>  $definition */
    private function included(array $definition, string $tierKey, string $meterKey): ?int
    {
        $included = $definition['included'] ?? null;

        if ($included === null) {
            return null;
        }

        if (! is_int($included) || $included < 0) {
            throw new InvalidArgumentException("Metered component '{$meterKey}' on tier '{$tierKey}': included must be a non-negative integer.");
        }

        return $included;
    }

    /** @param  array<array-key, mixed>  $definition */
    private function policy(array $definition, string $tierKey, string $meterKey): MeteringPolicy
    {
        $policy = $definition['policy'] ?? null;

        if ($policy === null) {
            return MeteringPolicy::FairUse;
        }

        $resolved = is_string($policy) ? MeteringPolicy::tryFrom($policy) : null;

        return $resolved ?? throw new InvalidArgumentException(
            "Metered component '{$meterKey}' on tier '{$tierKey}': unknown metering policy."
        );
    }

    private function money(mixed $price, string $tierKey, string $meterKey): ?Money
    {
        if ($price === null) {
            return null;
        }

        $amount = is_array($price) ? ($price['amount'] ?? null) : null;
        $currency = is_array($price) ? ($price['currency'] ?? null) : null;

        if (! is_int($amount) || ! is_string($currency)) {
            throw new InvalidArgumentException("Metered component '{$meterKey}' on tier '{$tierKey}': unit_price must be {amount, currency}.");
        }

        return Money::of($amount, $currency);
    }
}
