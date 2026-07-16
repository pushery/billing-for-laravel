<?php

declare(strict_types=1);

namespace Pushery\Billing\Entitlements;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\TierCatalog;

/**
 * Builds a ConfigEntitlements for a tier key, capturing the configured tier order as the upgrade
 * ranking once so every entitlement it produces compares against the same scale.
 */
final readonly class ConfigEntitlementsFactory
{
    public function __construct(
        private TierCatalog $catalog,
        private Repository $config,
    ) {}

    public function for(string $tierKey): ConfigEntitlements
    {
        return new ConfigEntitlements($tierKey, $this->catalog, $this->config, array_keys($this->catalog->all()));
    }
}
