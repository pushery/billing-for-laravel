<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;

/**
 * How much a seller sold across a border to consumers in one calendar year.
 *
 * ONE pot, not one per country. A threshold that applied per destination would be a different rule with the
 * same number, and a seller who spread the same turnover over five countries would stay under it forever
 * while owing tax in all five. The pot is the whole point of the threshold it feeds.
 *
 * Counting is separate from judging it, the same split the small-business monitor uses and for the same
 * reason: what counts as a cross-border consumer sale is a property of the data, while what the number means
 * is a property of a jurisdiction. A consumer whose sales live somewhere this package cannot see binds their
 * own counter and keeps the judgement.
 */
interface CrossBorderSalesCounter
{
    /** The net of everything sold across a border to consumers in that calendar year. */
    public function crossBorderNetIn(int $year, string $currency): Money;

    /**
     * The first sale of the year at which the running total passes a limit, or null when it never does.
     *
     * Its identity matters as much as the fact: the sale that crosses is itself taxed at the destination, so
     * a monitor that only answered "yes, at some point" would leave the caller unable to say which invoices
     * fall on which side of the line.
     *
     * @return ?array{reference: string, cumulativeMinor: int}
     */
    public function firstSaleAbove(int $year, string $currency, int $limitMinor): ?array;
}
