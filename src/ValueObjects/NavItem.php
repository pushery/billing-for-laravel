<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One account-hub navigation entry: a stable key, a label (an i18n key or literal), the route it
 * links to, its sort order and an optional icon. Pure value object — the registry builds these from
 * config and the shell renders them, so navigation is config-driven and a consumer never edits the
 * package to reshape the hub.
 */
final readonly class NavItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public int $order = 0,
        public ?string $icon = null,
    ) {}
}
