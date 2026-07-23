<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;

/**
 * Resolves a configured `provider_price` to the id for the active (or a named) provider — the anti-price-
 * injection allowlist for the money path, since only a price the config declares can ever be resolved.
 *
 * A `provider_price` may be declared two ways:
 *   - a scalar string — one price for whichever driver is active (the single-provider common case);
 *   - a per-provider map — `['stripe' => 'price_...']`, so one tier config carries
 *     the right id for each driver. The active driver is `billing.default` unless a provider is named.
 *
 * Anything else (missing, empty, a map with no entry for the provider) resolves to null — the caller then
 * treats the tier/add-on as not purchasable on that provider rather than injecting a wrong price.
 */
final readonly class ProviderPriceResolver
{
    public function __construct(private Repository $config) {}

    public function forTier(string $tierKey, ?string $provider = null): ?string
    {
        return $this->resolve($this->config->get("billing.tiers.{$tierKey}.provider_price"), $provider);
    }

    public function forAddon(string $addonKey, ?string $provider = null): ?string
    {
        return $this->resolve($this->config->get("billing.addons.{$addonKey}.provider_price"), $provider);
    }

    private function resolve(mixed $price, ?string $provider): ?string
    {
        if (is_string($price)) {
            return $price === '' ? null : $price;
        }

        if (is_array($price)) {
            $entry = $price[$provider ?? $this->activeProvider()] ?? null;

            return is_string($entry) && $entry !== '' ? $entry : null;
        }

        return null;
    }

    private function activeProvider(): string
    {
        $provider = $this->config->get('billing.default', 'stripe');

        return is_string($provider) && $provider !== '' ? $provider : 'stripe';
    }
}
