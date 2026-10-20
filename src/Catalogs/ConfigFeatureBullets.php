<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Pushery\Billing\Contracts\FeatureBullets;
use Pushery\Billing\Support\KeyedConfig;

/**
 * The bullets each tier lists in `billing.tiers.<key>.features`, translation keys resolved to the current locale in
 * the order they are listed. A missing or malformed list yields no bullets rather than a raw key on the page.
 */
final readonly class ConfigFeatureBullets implements FeatureBullets
{
    public function __construct(
        private Repository $config,
        private Translator $translator,
    ) {}

    public function forTier(string $tierKey): array
    {
        $keys = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'features');

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_map(
            $this->resolve(...),
            array_filter($keys, is_string(...)),
        ));
    }

    /** A translation key as a string; a key that resolves to a group, which is a misconfiguration, stays itself. */
    private function resolve(string $key): string
    {
        $value = $this->translator->get($key);

        return is_string($value) ? $value : $key;
    }
}
