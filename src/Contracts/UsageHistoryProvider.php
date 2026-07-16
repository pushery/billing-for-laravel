<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\AddonTopup;
use Pushery\Billing\ValueObjects\PeriodUsage;

/**
 * The read-side companion to {@see UsageProvider}: an owner's PAST usage, for the UsageHistory screen.
 * Where UsageProvider reports the current period from the project's live metering source, this reports
 * finished periods — and the package's default binding reads them column-authoritatively from the
 * persisted usage counters (never a provider call), so the history is always available even for a tier
 * whose live metering lives in a project backend. A project may bind its own implementation to source
 * history from elsewhere; the shape is what is fixed.
 */
interface UsageHistoryProvider
{
    /**
     * The owner's usage per meter across recent finished periods, newest first.
     *
     * @return list<PeriodUsage>
     */
    public function periods(Model $owner, int $limit = 12): array;

    /**
     * The owner's add-on top-up timeline (one-time purchases), newest first.
     *
     * @return list<AddonTopup>
     */
    public function topups(Model $owner, int $limit = 24): array;
}
