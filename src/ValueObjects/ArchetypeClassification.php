<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What follows, for tax and for the buyer's rights, from what was sold.
 *
 * Five answers travel together because they are read together and because a mismatched set is worse than a
 * missing one: a sale taxed under one shape and reported under another is internally consistent everywhere
 * anybody looks, and wrong only in the relationship between two places nobody compares.
 */
final readonly class ArchetypeClassification
{
    public function __construct(
        /** Which shape the sale has: the platform reselling, or arranging somebody else's sale. */
        public TaxonomyCell $regime,
        /** Whether it is taxed where the buyer is or where the seller is. */
        public TaxonomyCell $placeOfSupply,
        /** Which rate band applies. */
        public TaxonomyCell $rateCategory,
        /** Whether the sale goes into a platform's reporting obligation. */
        public TaxonomyCell $reportable,
        /** Which cancellation regime the buyer has. */
        public TaxonomyCell $withdrawal,
    ) {}
}
