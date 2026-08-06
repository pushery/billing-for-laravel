<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\ArchetypeClassification;

/**
 * What a jurisdiction makes of each kind of product.
 *
 * The archetypes themselves are a fact about commerce — a download is a download everywhere. What follows
 * from one is not: which rate band a text-only work gets, whether a commissioned piece is reportable, how
 * long a buyer may change their mind. So the core ships the shapes and this contract, and a profile fills
 * in the consequences. A consumer elsewhere swaps the profile and reads none of somebody else's tax law.
 */
interface ProductTaxonomy
{
    /** What follows from selling this kind of thing. */
    public function classify(TaxArchetype $archetype): ArchetypeClassification;
}
