<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What follows for a sale from how its seller stands: whether they sell as a business, whether the buyer has
 * the rights a consumer has against a business, and whether the seller issues an invoice.
 *
 * One value rather than three answers asked separately, because the three change together. A seller who
 * becomes a business owes the buyer consumer rights and owes them an invoice from the same sale on, and a
 * notice that updated one without the other would tell the buyer something the documents contradict.
 */
final readonly class SellerStandingConsequences
{
    public function __construct(
        public bool $sellsAsBusiness,
        public bool $buyerHasConsumerRights,
        public bool $sellerIssuesInvoice,
    ) {}
}
