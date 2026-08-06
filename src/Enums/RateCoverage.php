<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What this installation knows about taxing into a country.
 *
 * ## Three states, because two of them look identical from the outside
 *
 * The failure this exists to prevent is not "a country is missing". It is **a country answering when it
 * knows nothing**. A rate of 0% for a country nobody has classified reads exactly like a relief — the
 * document says zero, the return says zero, and nothing anywhere says the zero was a gap rather than a rule.
 *
 * So "we deliberately do not tax there" and "we have no idea" stop being the same answer. The first is a
 * decision somebody made and can defend; the second is a question nobody has answered yet, and it must
 * refuse rather than price.
 */
enum RateCoverage: string
{
    /** A rate is known and can be defended. */
    case Covered = 'covered';

    /**
     * Deliberately outside the tax area — the zero here is a decision, not an absence.
     *
     * A third country is the ordinary case: no union VAT is due, and that IS the answer rather than a gap
     * in one. What makes it different from `Unknown` is that somebody classified it.
     */
    case DeliberatelyUntaxed = 'deliberately_untaxed';

    /**
     * Nobody has answered for this country. Refuses to price.
     *
     * The whole point of the enum. Pricing this at zero would produce a document that claims a relief the
     * operator never established, and the only way to notice would be an audit asking why.
     */
    case Unknown = 'unknown';
}
