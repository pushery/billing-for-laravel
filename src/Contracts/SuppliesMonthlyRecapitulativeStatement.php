<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;

/**
 * When a jurisdiction wants the recapitulative statement for each month instead of each quarter.
 *
 * A marker a profile opts into, beside {@see SuppliesRecapitulativeStatementDeadline}, so a profile of a
 * consumer's own does not have to change. Article 263 of Directive 2006/112/EC lets a member state accept a
 * quarterly statement from a seller whose supplies of goods to businesses in other member states stay within
 * a limit, in the quarter and in each of the four before it; above that the statement is due for every month.
 * The limit and the monthly deadline are national, so they live in the profile.
 *
 * A profile without it has no monthly statement: every period is a quarter, and no limit is checked.
 */
interface SuppliesMonthlyRecapitulativeStatement
{
    /**
     * The limit on one quarter's supplies of goods, in euro cents, the currency the Directive states it in.
     * Above it, in the quarter or in one of the four before, the statement is due for each month.
     */
    public function recapitulativeStatementMonthlyThresholdMinor(): int;

    /** The last moment the statement for a month may be submitted. */
    public function recapitulativeStatementMonthDueOn(int $year, int $month): CarbonImmutable;
}
