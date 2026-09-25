<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * When a jurisdiction wants the recapitulative statement for a period.
 *
 * A capability of the jurisdiction profile, like the rates and the thresholds: the statement itself is the
 * same list of VAT ids everywhere, and only its deadline is national. A profile without it has no deadline
 * to report, and the export says so rather than guessing one.
 */
interface SuppliesRecapitulativeStatementDeadline
{
    /** The last moment the statement for the period may be submitted. */
    public function recapitulativeStatementDueOn(ReportingPeriod $period): CarbonImmutable;
}
