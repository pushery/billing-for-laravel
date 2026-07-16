<?php

declare(strict_types=1);

namespace Pushery\Billing\Account;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;

/**
 * The account-hub navigation renderer: it turns the flat, config-driven `billing.navigation` map into the
 * GROUPED, render-ready sidebar structure the shell draws, and supplies the typed document title.
 *
 * Config is the single source of truth (the same map {@see \Pushery\Billing\Support\Navigation} reads), so a
 * consumer adds, reorders, regroups or removes hub sections from config alone — the package never hard-codes a
 * list. Each item may carry an optional `group` (default "account") and `web_only` flag on top of its label,
 * route and order. Group labels come from `billing::account.nav.group.<group>`.
 *
 * An item is only surfaced when its route is actually registered (`Route::has`), so a section the consumer's
 * app has not built yet stays hidden instead of throwing; a `web_only` item is additionally hidden when the
 * runtime is native (config `billing.runtime`), for flows an app store forbids in-app. Item labels and group
 * labels are returned as i18n KEYS for the view to translate (matching the existing nav rendering);
 * `activeTitle()` returns the already-translated label of the active item because a document title is used
 * as a raw string.
 */
final readonly class Navigation
{
    public function __construct(private Repository $config, private Translator $translator) {}

    /**
     * The visible, grouped navigation. Empty groups (every item route-missing or runtime-gated away) drop out.
     *
     * @return list<array{key: string, label: string, items: list<array{key: string, label: string, url: string, active: bool}>}>
     */
    public function visible(): array
    {
        $native = $this->config->get('billing.runtime') === 'native';

        /** @var array<string, array{order: int, items: list<array{key: string, label: string, url: string, active: bool, order: int}>}> $groups */
        $groups = [];

        foreach ($this->items() as $item) {
            if (! Route::has($item['route'])) {
                continue; // section not built by the consuming app — hide, don't crash
            }

            if ($item['web_only'] && $native) {
                continue; // web-only flow suppressed on a native runtime
            }

            $url = $this->safeUrl($item['route']);

            if ($url === null) {
                continue; // route needs parameters the hub cannot supply — hide, don't crash
            }

            $group = $item['group'];
            $groups[$group] ??= ['order' => $item['order'], 'items' => []];
            $groups[$group]['order'] = min($groups[$group]['order'], $item['order']);
            $groups[$group]['items'][] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => $url,
                'active' => request()->routeIs($item['route']),
                'order' => $item['order'],
            ];
        }

        // Groups sort by their earliest item; items within a group keep their own order.
        uasort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $visible = [];

        foreach ($groups as $key => $group) {
            $visible[] = [
                'key' => $key,
                'label' => "billing::account.nav.group.{$key}",
                'items' => array_map(
                    static fn (array $item): array => ['key' => $item['key'], 'label' => $item['label'], 'url' => $item['url'], 'active' => $item['active']],
                    $group['items'],
                ),
            ];
        }

        return $visible;
    }

    /**
     * The localized label of the active nav item (the one whose route the current request matches), or null
     * when nothing matches — the layout then falls back to the app name.
     */
    public function activeTitle(): ?string
    {
        foreach ($this->items() as $item) {
            if (Route::has($item['route']) && request()->routeIs($item['route'])) {
                return $this->translator->get($item['label']);
            }
        }

        return null;
    }

    /** Resolve a route to its URL, or null when it needs parameters the hub cannot supply — hide, don't crash. */
    private function safeUrl(string $route): ?string
    {
        try {
            return route($route);
        } catch (UrlGenerationException) {
            return null;
        }
    }

    /**
     * The configured items, parsed defensively and sorted by order (ties keep config order). A malformed
     * entry — missing/non-string label or route — is dropped rather than rendered broken or throwing.
     *
     * @return list<array{key: string, label: string, route: string, group: string, web_only: bool, order: int}>
     */
    private function items(): array
    {
        $configured = $this->config->get('billing.navigation');

        if (! is_array($configured)) {
            return [];
        }

        $items = [];

        foreach ($configured as $key => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $label = $spec['label'] ?? null;
            $route = $spec['route'] ?? null;
            if (! is_string($label)) {
                continue;
            }
            if (! is_string($route)) {
                continue;
            }

            $specKey = $spec['key'] ?? null;
            $group = $spec['group'] ?? null;
            $order = $spec['order'] ?? null;

            $items[] = [
                'key' => is_string($specKey) ? $specKey : (string) $key,
                'label' => $label,
                'route' => $route,
                'group' => is_string($group) && $group !== '' ? $group : 'account',
                'web_only' => (bool) ($spec['web_only'] ?? false),
                'order' => is_int($order) ? $order : 0,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $items;
    }
}
