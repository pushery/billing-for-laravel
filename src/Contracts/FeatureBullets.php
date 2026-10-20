<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The feature bullets a pricing card shows for a tier.
 *
 * The package answers from `billing.tiers.<key>.features`, a list of translation keys. An application whose bullets
 * carry numbers that follow from its own limits, a count of translations a month worked out from a budget, binds an
 * implementation of its own that computes them, so a changed limit changes the bullet and nobody edits a
 * translation. `PricingCatalog` asks it for every card, the full set and the upgrade grid alike.
 */
interface FeatureBullets
{
    /**
     * The tier's bullets as finished strings in the current locale, in the order the card shows them. A tier with
     * none answers an empty list.
     *
     * @return list<string>
     */
    public function forTier(string $tierKey): array;
}
