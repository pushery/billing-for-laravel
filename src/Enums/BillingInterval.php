<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The recurrence interval of a plan. Provider-neutral: each driver maps it onto its own interval
 * vocabulary (Stripe day/week/month/year, a local Mollie/Adyen engine cycle).
 */
enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Approximate number of billing periods per year — used only to annualise prices for
     * "save X% with yearly" style comparisons, never for actual proration maths.
     */
    public function perYear(): int
    {
        return match ($this) {
            self::Day => 365,
            self::Week => 52,
            self::Month => 12,
            self::Year => 1,
        };
    }
}
