<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\QuotaSnapshot;

/**
 * The one project-specific seam: report an owner's current metered usage as a QuotaSnapshot of 0..N
 * dimensions. An empty snapshot means the tier is unmetered, so the usage panel shows nothing rather
 * than a misleading zeroed gauge. The metering SOURCE stays project-defined; only this shape is fixed.
 */
interface UsageProvider
{
    public function snapshot(Model $billable): QuotaSnapshot;
}
