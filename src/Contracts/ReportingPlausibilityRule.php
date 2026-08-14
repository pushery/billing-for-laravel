<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\PlausibilityFinding;
use Pushery\Billing\ValueObjects\SellerPeriodReport;

/**
 * One rule in the catalog a period is checked against before anything is filed.
 *
 * ## Why a rule sees the whole period rather than one seller
 *
 * Some of what makes a filing wrong is not a property of any single row. Two rows for one seller is a
 * duplicate, and neither row is wrong on its own; a period whose sellers all check out individually can
 * still be one nobody may file. A per-seller signature would make those rules impossible to express, and
 * the ones that ARE per-seller lose nothing by looping.
 *
 * ## Why the catalog is not core code
 *
 * A consumer under a different reporting duty brings their own rules rather than switching off somebody
 * else's. Switching off is the worse mechanism twice over: the shipped rule stays in the code reading as
 * though it applies, and the consumer's actual duty is nowhere. So this is an interface, the registry
 * collects rules from the package, the active profile and the consumer, and a jurisdiction's specifics
 * live in its profile.
 */
interface ReportingPlausibilityRule
{
    /**
     * A stable identifier, in snake_case.
     *
     * Half of an acknowledgement's key, which is what makes it stable rather than merely conventional: a
     * renamed rule un-acknowledges every finding an operator already answered for, silently, and the run
     * that reports them again looks like a regression rather than a rename.
     */
    public function key(): string;

    /**
     * The period is passed rather than injected, and that is what lets one rule instance serve every run.
     * A rule that took its year in the constructor would have to be rebuilt per run, which puts the
     * registry in the business of constructing rules instead of holding them.
     *
     * @param  list<SellerPeriodReport>  $reports  every seller in the period, in the run's own order
     * @param  string  $currency  the ISO code this period is reported in — a period is one currency, and a
     *                            figure from another one is a different report, not a converted line
     * @return list<PlausibilityFinding>
     */
    public function evaluate(array $reports, int $year, string $currency): array;
}
