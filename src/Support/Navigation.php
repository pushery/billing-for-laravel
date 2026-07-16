<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\NavItem;

/**
 * The config-driven account-hub navigation registry: reads config('billing.navigation') into an
 * ordered list of NavItems for the shell to render. An entry without a usable label and route is
 * dropped rather than rendered broken, and items sort by their order (ties keeping config order, so
 * the ordering is stable). This is why a consumer can add, reorder or remove hub sections from config
 * alone.
 */
final readonly class Navigation
{
    public function __construct(private Repository $config) {}

    /** @return list<NavItem> */
    public function items(): array
    {
        $configured = $this->config->get('billing.navigation');

        if (! is_array($configured)) {
            return [];
        }

        $items = [];

        foreach ($configured as $key => $spec) {
            $item = $this->item((string) $key, $spec);

            if ($item instanceof NavItem) {
                $items[] = $item;
            }
        }

        usort($items, fn (NavItem $a, NavItem $b): int => $a->order <=> $b->order);

        return $items;
    }

    private function item(string $key, mixed $spec): ?NavItem
    {
        if (! is_array($spec)) {
            return null;
        }

        $label = $spec['label'] ?? null;
        $route = $spec['route'] ?? null;

        if (! is_string($label) || ! is_string($route)) {
            return null;
        }

        $specKey = $spec['key'] ?? null;
        $order = $spec['order'] ?? null;
        $icon = $spec['icon'] ?? null;

        return new NavItem(
            key: is_string($specKey) ? $specKey : $key,
            label: $label,
            route: $route,
            order: is_int($order) ? $order : 0,
            icon: is_string($icon) ? $icon : null,
        );
    }
}
