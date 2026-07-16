<?php

declare(strict_types=1);

namespace Pushery\Billing\Usage;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UsageProvider;
use Pushery\Billing\ValueObjects\QuotaSnapshot;

/**
 * The default usage provider: an empty snapshot, meaning "unmetered". The metering source is
 * project-specific, so the package binds this no-op until the consuming app supplies its own
 * UsageProvider — the usage panel then shows nothing rather than a misleading zeroed gauge.
 */
final readonly class NullUsageProvider implements UsageProvider
{
    public function snapshot(Model $billable): QuotaSnapshot
    {
        return new QuotaSnapshot;
    }
}
