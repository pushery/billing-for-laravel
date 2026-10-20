<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\GoodsConsignment;

/**
 * A catalog that can say how one of its entries ships, where the entry is goods.
 *
 * A sibling of {@see SuppliesProductArchetypes}, and for the same reason: `AddonCatalog` is implemented outside
 * this package, so a method added there is a fatal error in code this package does not own. A catalog either
 * states consignments or it does not, and the type system answers that before anything runs.
 *
 * The hosted one-off purchase asks it for a routed sale of goods, because whether the platform is treated as
 * the supplier of those goods turns on where the transport begins and what the consignment is worth, and the
 * archetype says neither. A routed sale through `RoutedPayment::charge()` passes the consignment itself.
 */
interface SuppliesGoodsConsignments
{
    /**
     * The consignment the entry ships as, or null where it is no goods or the catalog does not know.
     *
     * Null is an answer: a seller outside the Union whose goods arrive without a consignment is refused as
     * before, because nothing can then tell a deemed supply from the seller's own.
     */
    public function consignmentFor(string $addonKey): ?GoodsConsignment;
}
