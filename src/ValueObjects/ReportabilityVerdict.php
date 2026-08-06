<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\ReportabilityReason;

/**
 * Whether a seller falls under the reporting duty, and on what ground.
 *
 * The ground is not decoration. Both directions of this decision carry a penalty — failing to report is one
 * offense, and reporting somebody the law leaves out is another, because it hands a person's data to an
 * authority with nothing entitling anyone to it. "When in doubt, report" is therefore not the safe side; it
 * is the second mistake. A verdict that cannot say which branch decided it cannot be defended in either
 * direction.
 */
final readonly class ReportabilityVerdict
{
    public function __construct(public ReportabilityReason $reason) {}

    public function reportable(): bool
    {
        return $this->reason->reportable();
    }
}
