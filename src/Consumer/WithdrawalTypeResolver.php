<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Contracts\SuppliesBuyerAudiences;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BuyerAudience;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * What kind of withdrawal right an add-on or a subscription tier carries, read from the taxonomy rather
 * than assumed.
 *
 * ## Why this is its own class
 *
 * Two places need the answer and they sit at opposite ends of the purchase: the checkout, which must refuse
 * to send a buyer to the provider without the declarations their purchase requires, and the grant effect,
 * which must refuse to provide the work without them. Both answers have to be the SAME answer — a checkout
 * that decided no declarations were needed and a grant that then refused to provide would take money for
 * something the buyer can never receive.
 *
 * It was a private method on the grant effect first. Copying it to the checkout would have been the cheaper
 * edit and the wrong one: the same rule in two readers drifts, and here the drift is silent in the direction
 * that costs money.
 *
 * ## Every hop may legitimately answer nothing, and null is not a default
 *
 * A catalog that does not supply archetypes at all is the shipped state — `AddonCatalog` is implemented
 * outside this package, so the capability is asked for by type rather than assumed. An add-on nobody has
 * classified answers null. And a taxonomy cell that delegates or defers its answer names no type. The shipped
 * taxonomy fixes the withdrawal of every archetype, a voucher's among them: buying one is refundable in the ordinary
 * way, whatever its tax waits for. A taxonomy of your own may leave it open.
 *
 * Null means UNCLASSIFIED, never "no right applies". What a caller does with that is the caller's decision,
 * and the two callers make opposite ones on purpose — see {@see PurchaseDeclarations::assertMayCheckout()}.
 */
final readonly class WithdrawalTypeResolver
{
    public function __construct(
        private AddonCatalog $addons,
        private ProductTaxonomy $taxonomy,
        private ?TierCatalog $tiers = null,
        private ?AddonContentMap $works = null,
    ) {}

    /**
     * The withdrawal type this add-on carries, or null when nothing classifies it.
     *
     * An add-on sold only to businesses carries none: no consumer is party to the contract. One whose configuration
     * grants prepaid units and hands over no work says what it is in so many words, which an add-on nobody
     * classified does not; nothing is handed over at the purchase that a right could end on, so the ordinary window
     * applies.
     */
    public function forAddon(string $addonKey): ?WithdrawalType
    {
        if ($this->addons instanceof SuppliesBuyerAudiences && $this->addons->audienceFor($addonKey) === BuyerAudience::Business) {
            return WithdrawalType::NotApplicable;
        }

        $archetype = $this->addons instanceof SuppliesProductArchetypes ? $this->addons->archetypeFor($addonKey) : null;

        if ($archetype instanceof TaxArchetype) {
            return $this->withdrawalOf($archetype);
        }

        return $this->isUnitPack($addonKey) ? WithdrawalType::PlainRefundWindow : null;
    }

    /**
     * The withdrawal type a subscription to this tier carries, or null when the taxonomy fixes none.
     *
     * A tier is a subscription unless its catalog says otherwise, so an unset archetype is not unclassified here
     * the way it is for an add-on: `TaxArchetype::Subscription` IS the classification, and the explicit
     * `archetype` key refines it for a tier that sells something whose right ends differently. Null still means
     * UNCLASSIFIED, and it is reached where the taxonomy itself leaves the withdrawal answer open.
     */
    public function forTier(string $tierKey): ?WithdrawalType
    {
        // Sold only to businesses: no consumer is party to the contract, so no right arises.
        if ($this->tiers instanceof SuppliesBuyerAudiences && $this->tiers->audienceFor($tierKey) === BuyerAudience::Business) {
            return WithdrawalType::NotApplicable;
        }

        $archetype = $this->tiers instanceof SuppliesProductArchetypes ? $this->tiers->archetypeFor($tierKey) : null;

        return $this->withdrawalOf($archetype ?? TaxArchetype::Subscription);
    }

    /** Whether this add-on grants prepaid units and hands over no work, which only a map of works can tell. */
    private function isUnitPack(string $addonKey): bool
    {
        return $this->works instanceof AddonContentMap
            && ! $this->works->contentFor($addonKey) instanceof ContentReference
            && $this->addons->grantsFor($addonKey) instanceof UnitGrant;
    }

    /** The withdrawal answer the taxonomy fixes for an archetype, or null when it delegates or defers it. */
    private function withdrawalOf(TaxArchetype $archetype): ?WithdrawalType
    {
        $cell = $this->taxonomy->classify($archetype)->withdrawal;

        if (! $cell->isFixed()) {
            return null;
        }

        $value = $cell->value();

        return $value instanceof WithdrawalType ? $value : null;
    }
}
