<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which band of a country's rates applies — not the rate itself.
 *
 * The two are separate on purpose. A category is a property of what was sold and is stable; a rate is a
 * property of a country at a moment and moves. Freezing only the rate would lose why it was that rate;
 * freezing only the category would leave a correction re-deriving a number that has since changed.
 */
enum TaxRateCategory: string
{
    case Standard = 'standard';

    /** The reduced band, where a country grants one for this kind of supply. */
    case Reduced = 'reduced';
}
