<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\ValueObjects\SellerStandingConsequences;

/**
 * What a regime makes of a seller's standing for the sales they make: the buyer's rights and the document.
 *
 * In the profile rather than the core, because both are rules of a jurisdiction. The core only asks, for the
 * standing it has recorded, and hands the answer on as one value.
 */
interface DescribesSellerStanding
{
    public function consequencesOf(CreatorTaxStatus $standing): SellerStandingConsequences;
}
