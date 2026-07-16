<?php

declare(strict_types=1);

namespace Pushery\Billing\Usage;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Contracts\UsageProvider;
use Pushery\Billing\Support\PeriodResolver;
use Pushery\Billing\Support\UsageMeter;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\MeteredDimension;
use Pushery\Billing\ValueObjects\QuotaSnapshot;

/**
 * The usage provider for apps that meter through this package: it reads the owner's own counters for
 * the current billing cycle and reports one dimension per metered component of their tier.
 *
 * The gauge's ceiling is the tier's INCLUDED allowance, which is what an owner wants to see — "7 300 of
 * your 10 000 included emails" — not a hard cap. Whether going past it costs money (a metered
 * component) or is simply refused (a quota) is what the metering policy says, and the screen renders
 * that. A tier with no metered components reports an empty snapshot, so the panel stays silent rather
 * than showing a zeroed gauge that means nothing.
 */
final readonly class CounterUsageProvider implements UsageProvider
{
    public function __construct(
        private MeterCatalog $meters,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private UsageMeter $counters,
    ) {}

    public function snapshot(Model $billable): QuotaSnapshot
    {
        $period = $this->periods->forOwner($billable);
        $components = $this->meters->forTier($this->tiers->resolve($billable)->key);

        $dimensions = array_map(
            fn (MeteredComponent $component): MeteredDimension => new MeteredDimension(
                key: $component->key,
                label: $component->label,
                used: $this->counters->used($billable, $component->key, $period->key),
                limit: $component->included,
                unit: $component->unit,
                period: $period->key,
                warnThreshold: $component->warnThreshold,
                policy: $component->policy,
                resetAt: $period->end,
            ),
            $components,
        );

        return new QuotaSnapshot($dimensions);
    }
}
