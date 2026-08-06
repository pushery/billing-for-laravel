<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What the BUYER is, as far as tax is concerned — the question that has to be answered before the product's
 * own classification means anything.
 *
 * The order matters and was the defect. A product carries a rule for where it is taxed, and that rule is
 * written for consumers; a validated business in another country moves the place of supply regardless of
 * what the product says. Reading the product first produces a consumer answer for a business buyer, and
 * nothing about the resulting document looks wrong — it charges a real rate, to a real country, and
 * reports it into a scheme that only exists for consumers.
 *
 * `Consumer` is the fail-closed default, and every unproven case lands there: an id that is merely present,
 * one a registry could not confirm, an outage. Charging tax that was not owed is recoverable; not charging
 * tax that was owed is not.
 */
enum RecipientTaxStatus: string
{
    /** Not a business, or a business that has not proven it. Everything unproven belongs here. */
    case Consumer = 'consumer';

    /** A business in the same tax union as the seller, whose registration a registry confirmed. */
    case UnionBusinessValidated = 'union_business_validated';

    /** A business outside the union entirely. */
    case NonUnionBusiness = 'non_union_business';
}
