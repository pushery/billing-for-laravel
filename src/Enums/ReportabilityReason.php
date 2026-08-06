<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a seller was, or was not, judged reportable.
 *
 * Carried with every verdict and stored beside it, because a classification nobody can account for is worth
 * nothing when somebody asks. "We reported them" is not an answer; "we reported them because the work was
 * commissioned for one buyer" is.
 *
 * The reasons are a closed set for the same purpose: a free-text explanation cannot be counted, compared
 * between periods, or checked for a branch that has quietly stopped firing.
 */
enum ReportabilityReason: string
{
    /** Work done for one buyer to their brief — the case a reporting duty is written for. */
    case IndividuallyCommissioned = 'individually_commissioned';

    /** Goods, sold past the point at which the exemption stops applying. */
    case GoodsAboveDeMinimis = 'goods_above_de_minimis';

    /** Goods, but few enough and small enough that the law leaves them out. */
    case GoodsWithinDeMinimis = 'goods_within_de_minimis';

    /**
     * Sold off the shelf to whoever wants it.
     *
     * There is no small-scale exemption to reach for here, in either direction: standardized supply is out
     * of scope however much of it there is, and commissioned work is in scope however little.
     */
    case Standardized = 'standardized';

    /** No profile is deciding anything, so nothing is reportable. */
    case NoReportingRegime = 'no_reporting_regime';

    public function reportable(): bool
    {
        return $this === self::IndividuallyCommissioned || $this === self::GoodsAboveDeMinimis;
    }
}
