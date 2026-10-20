<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The number each consignment shipped under, where the platform takes part in delivering the goods it is treated as
 * supplying.
 *
 * The records of a supply declared in the import scheme carry the unique consignment number where the platform is
 * directly involved in the delivery (Art. 63c(2) Implementing Regulation 282/2011). The number exists once the goods
 * ship, after the sale, and it lives where the shipping does: in the application. A platform that does not deliver
 * binds nothing, and the records leave the column empty.
 */
interface SuppliesConsignmentNumbers
{
    /** The number the goods of this sale shipped under, or null where none is known. */
    public function consignmentNumberFor(string $provider, string $chargeReference): ?string;
}
