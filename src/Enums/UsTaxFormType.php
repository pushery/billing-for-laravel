<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which declaration a seller gave about where they are taxed, in the United States regime.
 *
 * Three forms rather than one flag, because they are not degrees of the same thing: one is a US person's
 * declaration, one is a foreign individual's, one is a foreign entity's. Collapsing them would lose exactly
 * the distinction that decides whether anything is withheld and what, if anything, gets reported — and it
 * would lose it silently, since all three arrive as "the seller filled something in".
 */
enum UsTaxFormType: string
{
    /** A US person declaring their taxpayer identification. */
    case W9 = 'w9';

    /** A foreign individual declaring they are not one. */
    case W8Ben = 'w8ben';

    /** A foreign entity declaring the same. */
    case W8BenE = 'w8bene';

    /** Whether the declaration is a foreign one, which is what the two different obligations turn on. */
    public function foreign(): bool
    {
        return $this !== self::W9;
    }
}
