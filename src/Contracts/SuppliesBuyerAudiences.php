<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\BuyerAudience;

/**
 * A catalog that says who may buy each of its offers.
 *
 * Asked by type, like `SuppliesProductArchetypes`: a catalog implemented outside the package does not have to
 * answer, and one that does not sells every offer to anyone.
 */
interface SuppliesBuyerAudiences
{
    /** Who may buy the offer with this key. */
    public function audienceFor(string $key): BuyerAudience;
}
