<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A reporting figure was asked for on an installation that has switched the counter off.
 *
 * Raised rather than answered with zero, and the difference is the whole reason this class exists. Zero is a
 * real reporting answer — *this seller received nothing in the window* — and it is one that gets filed. If a
 * disabled counter returned it, a platform that turned the counter off would produce a return stating that
 * every seller earned nothing, with no error anywhere and every figure internally consistent.
 *
 * Nobody would look, because there would be nothing to look at. That is the failure mode this package keeps
 * finding in itself: an absence read as an answer.
 *
 * The switch exists so a platform outside the regime stops carrying a counter for a duty it does not have.
 * It is not a way to make the figures go away while still asking for them.
 */
final class ReportingCounterDisabled extends RuntimeException
{
    public static function forSubdivisionSales(): self
    {
        return new self(
            'Buyer gross per subdivision was asked for, and billing.tax_counters.us_state_gmv.enabled is '
            .'off. The switch exists so a platform with no subdivision-level obligation stops carrying a '
            .'counter it does not need — not so the figure can be made to disappear while still being '
            .'asked for. A zero here would read as "we sold nothing into that state", which is exactly the '
            .'sentence a nexus threshold is watched for.'
        );
    }

    public static function forWithheldFees(): self
    {
        return new self(
            'The reporting withheld-fee counter is switched off (billing.tax_counters.dac7.enabled), so '
            .'there is no figure to give. Refused rather than answered with zero for the same reason as its '
            .'sibling: a zero here states that a seller had nothing withheld, which is a claim about their '
            .'compensation rather than the absence of one. Turn the counter on for an installation that has '
            .'the reporting duty, or stop asking it for figures on one that does not.'
        );
    }

    public static function forGrossInflow(): self
    {
        return new self(
            'The reporting gross-inflow counter is switched off (billing.tax_counters.dac7.enabled), so '
            .'there is no figure to give. This is refused rather than answered with zero because zero is '
            .'itself a reportable answer — a return built on it would state that every seller received '
            .'nothing. Turn the counter on for an installation that has the reporting duty, or stop asking '
            .'it for figures on one that does not.'
        );
    }
}
