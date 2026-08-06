<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;

/**
 * A jurisdiction profile that knows which countries share its tax union.
 *
 * ## Why this is a question and not a constant
 *
 * A tax union's membership is a political fact with a date on it. Members join, members leave — and when one
 * leaves, every rule keyed to "is this the union?" changes meaning overnight for that country while the code
 * still says what it said yesterday. A list compiled into a core class cannot express that it is from a
 * particular year, so nothing can report that it has gone stale, and the failure is silent in both
 * directions: a former member keeps being treated as internal, a new one keeps being treated as foreign.
 *
 * ## Why it belongs to the profile and not the core
 *
 * "Is this country in my union" is only answerable by somebody who has a union. An operator in a
 * jurisdiction with no such grouping has no answer, and the honest result there is that nothing is treated
 * as internal — not that some other continent's grouping is used by default. A core constant cannot express
 * "I have no opinion"; a profile that simply does not implement this can.
 *
 * ## Not a market list
 *
 * This says who shares the tax rules, never where an operator has chosen to sell. That second question is
 * the market allowlist, is opt-in, and is the operator's own decision — conflating the two would turn a
 * factual grouping into a business policy, and a business policy into a claim about law.
 */
interface DefinesUnionMembership
{
    /**
     * The countries sharing this jurisdiction's tax union, as ISO-3166-1 alpha-2 codes.
     *
     * The profile's own country is included: it is a member of its own union, and a caller asking "is the
     * buyer internal" for a domestic sale must get a truthful yes rather than an edge case.
     *
     * @return list<string>
     */
    public function unionMembers(): array;

    /** The day that membership was known to be correct, so its age can be reported rather than assumed. */
    public function unionMembersValidFrom(): CarbonInterface;
}
