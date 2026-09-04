<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Carbon\CarbonInterface;

/**
 * The recurrence interval of a plan. Provider-neutral: each driver maps it onto its own interval
 * vocabulary (Stripe day/week/month/year, a local engine cycle).
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

    /**
     * The same moment one interval later — the local engine's answer to "when does the next cycle end",
     * for a driver with no provider to ask.
     *
     * Month and year advance WITHOUT overflow, and that is the whole reason this lives on the enum rather
     * than at each call site. Plain month arithmetic turns 31 January into 3 March, and the subscriber's
     * anchor day is then gone for good: every later cycle inherits the drift, and the customer who signed
     * up on the 31st is billed on the 3rd forever after. No-overflow lands on the last day of the shorter
     * month instead and returns to the 31st when the month allows it again, which is what "monthly on the
     * 31st" means to the person paying.
     */
    public function advance(CarbonInterface $from): CarbonInterface
    {
        $moment = $from->copy();

        return match ($this) {
            self::Day => $moment->addDay(),
            self::Week => $moment->addWeek(),
            self::Month => $moment->addMonthNoOverflow(),
            self::Year => $moment->addYearNoOverflow(),
        };
    }
}
