<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The named conditions under which the United States regime has to be switched on.
 *
 * They are an enum rather than a paragraph of documentation because the alternative is that somebody, two
 * years from now, has to decide from scratch what "enough" meant — while a counter is already flashing. A
 * condition with a name can be pointed at by an alarm, tested, and reported; a condition described in prose
 * gets re-argued every time it nearly fires.
 */
enum UsRegimeActivationTrigger: string
{
    /** An entity or fixed place of business in the country — the one trigger that admits no judgement. */
    case DomesticEstablishment = 'domestic_establishment';

    /**
     * The platform's own turnover or transaction count is closing on a region's limit.
     *
     * Measured across ALL sellers, not per seller: where a marketplace is treated as the facilitator, the
     * limit is the platform's, and "no single seller is anywhere near it" is the reflex that gets a platform
     * registered late.
     */
    case ApproachingRegionalLimit = 'approaching_regional_limit';

    /** A deliberate decision to sell into the market, which needs no counter to be true. */
    case MarketDecision = 'market_decision';
}
