<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\WithdrawalType;

/**
 * What kind of withdrawal right an add-on carries, read from the taxonomy rather than assumed.
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
 * classified answers null. And a taxonomy cell that is a delegation or a deferral names no type: a tip's
 * withdrawal right belongs to whatever it was given on, and a voucher's is not decided until redemption.
 *
 * Null means UNCLASSIFIED, never "no right applies". What a caller does with that is the caller's decision,
 * and the two callers make opposite ones on purpose — see {@see PurchaseDeclarations::assertMayCheckout()}.
 */
final readonly class WithdrawalTypeResolver
{
    public function __construct(
        private AddonCatalog $addons,
        private ProductTaxonomy $taxonomy,
    ) {}

    /** The withdrawal type this add-on carries, or null when nothing classifies it. */
    public function forAddon(string $addonKey): ?WithdrawalType
    {
        if (! $this->addons instanceof SuppliesProductArchetypes) {
            return null;
        }

        $archetype = $this->addons->archetypeFor($addonKey);

        if (! $archetype instanceof TaxArchetype) {
            return null;
        }

        $cell = $this->taxonomy->classify($archetype)->withdrawal;

        if (! $cell->isFixed()) {
            return null;
        }

        $value = $cell->value();

        return $value instanceof WithdrawalType ? $value : null;
    }
}
