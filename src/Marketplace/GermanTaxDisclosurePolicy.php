<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\TaxDisclosurePolicy;
use Pushery\Billing\Enums\CreatorTaxStatus;

/**
 * The German reading: tax may be stated on a self-billed document ONLY for a validated, standard-rated
 * domestic creator. Everyone else — small business, a business abroad, a private individual, a creator whose
 * standing is still awaiting validation, or one that was never established — is blocked.
 *
 * This is a positive list of one, and that is the whole point. A negative list ("block small businesses and
 * private individuals") would let any future standing through silently, and here that is not a cosmetic
 * defect: § 14c Abs. 2 UStG makes the RECIPIENT of a self-billed document owe any tax it wrongly states, so
 * a slip issues a stranger a tax bill. Asking "is it the one permitted standing?" blocks the unknown by
 * construction. A creator awaiting validation is deliberately NOT on the list: their supply is taxable, but
 * the statement of that tax waits for the registry, so their document is issued net for now.
 */
final class GermanTaxDisclosurePolicy implements TaxDisclosurePolicy
{
    public function permitsTaxDisclosure(CreatorTaxStatus $status): bool
    {
        return $status === CreatorTaxStatus::DomesticStandardRated;
    }
}
