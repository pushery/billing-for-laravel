<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether a configured tier is untouchable: granted by hand and kept out of the billing flow.
 *
 * Two settings say it, and both count: the `billing.untouchable_tiers` list and an `untouchable` flag on the tier
 * itself. Every reader asks here, so the catalog that offers a tier, the checkout that sells it and the plan sync
 * that leaves an owner on it give one answer. An answer that differed between them would let a tier be bought that
 * the sync then never moves an owner off, a canceled subscription included.
 */
final class UntouchableTiers
{
    public static function has(Repository $config, string $tier): bool
    {
        $listed = $config->get('billing.untouchable_tiers', []);

        return (is_array($listed) && in_array($tier, $listed, true))
            || KeyedConfig::setting($config, 'billing.tiers', $tier, 'untouchable') === true;
    }
}
