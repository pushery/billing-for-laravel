<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Profiles;

use Pushery\Billing\Contracts\DescribesSellerStanding;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\ValueObjects\SellerStandingConsequences;

/**
 * The German reading of a seller's standing, for the sales they make.
 *
 * A seller who sells as a business is a trader towards a consumer, so the buyer has a consumer's rights,
 * including withdrawal and the statutory warranty, and the seller issues an invoice, a small business
 * included. A private individual is neither: the buyer contracts with somebody who is not a trader, and no
 * invoice is owed. An unestablished standing reads as private here; selling on it is refused elsewhere.
 */
final readonly class GermanSellerStanding implements DescribesSellerStanding
{
    public function consequencesOf(CreatorTaxStatus $standing): SellerStandingConsequences
    {
        $business = $standing->isBusiness();

        return new SellerStandingConsequences(
            sellsAsBusiness: $business,
            buyerHasConsumerRights: $business,
            sellerIssuesInvoice: $business,
        );
    }
}
