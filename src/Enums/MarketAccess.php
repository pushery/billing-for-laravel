<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether an operator may sell into a country.
 *
 * Three states rather than two, because "not yet" and "no" are different intentions and a reader of the
 * configuration should be able to tell which one they are looking at. A market on the roadmap that somebody
 * silently reads as closed for good is how an opening gets forgotten.
 *
 * The package never checks whether a registration really exists. This is the operator's own statement, and
 * all the package does is refuse to sell where no such statement has been made.
 */
enum MarketAccess: string
{
    /** The operator is registered here and sales may happen. */
    case Open = 'open';

    /** On the roadmap and not yet registered. Refused exactly like a closed market. */
    case Planned = 'planned';

    /** Deliberately not served. */
    case Blocked = 'blocked';

    /** Whether a sale into this market may proceed. */
    public function permitsSale(): bool
    {
        return $this === self::Open;
    }
}
