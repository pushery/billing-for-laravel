<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * An owner's usage across every metered dimension for the current period — the value the shared
 * UsageProvider returns and the usage panel renders. An empty snapshot means the tier is unmetered
 * (e.g. an Unlimited / BYOK tier), so the panel shows nothing rather than a misleading zeroed gauge.
 */
final readonly class QuotaSnapshot
{
    /** @param list<MeteredDimension> $dimensions */
    public function __construct(public array $dimensions = []) {}

    public function isEmpty(): bool
    {
        return $this->dimensions === [];
    }

    /** The headline dimension (first declared), or null when unmetered. */
    public function primary(): ?MeteredDimension
    {
        return $this->dimensions[0] ?? null;
    }

    public function dimension(string $key): ?MeteredDimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->key === $key) {
                return $dimension;
            }
        }

        return null;
    }

    /** Any dimension at or past its warning threshold. */
    public function isWarning(): bool
    {
        return array_any($this->dimensions, fn (MeteredDimension $dimension): bool => $dimension->isWarning());
    }

    /** Any dimension over its ceiling. */
    public function isOver(): bool
    {
        return array_any($this->dimensions, fn (MeteredDimension $dimension): bool => $dimension->isOver());
    }
}
