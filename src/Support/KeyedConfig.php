<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * One setting of a keyed configuration entry, such as a tier's price or an add-on's label.
 *
 * The configuration repository reads a dot as nesting, so a path built from an entry's key, such as
 * `billing.tiers.{key}.label`, splits a key that holds a dot and finds nothing: a tier named `pro.annual`
 * would have no price, no label and no interval. This reads the map, takes the entry by its literal key, and
 * only then walks the setting's own path, the way the tier catalog reads its key set.
 *
 * @internal
 */
final class KeyedConfig
{
    /**
     * @param  string  $map  the configuration map the entries sit in, such as `billing.tiers`
     * @param  string  $key  the entry's key, read literally, dots included
     * @param  string  $path  the setting inside the entry, dot-separated, such as `price_display.amount`;
     *                        empty for the whole entry
     */
    public static function setting(Repository $config, string $map, string $key, string $path = ''): mixed
    {
        $entries = $config->get($map);
        $value = is_array($entries) ? ($entries[$key] ?? null) : null;

        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return $value;
    }
}
