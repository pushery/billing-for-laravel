<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One charged fee, resolved into what it is worth and what tax it carries.
 *
 * A buyer fee is a supply the PLATFORM makes to the buyer, not a part of the item's price and not a slice
 * of the seller's turnover. Keeping it as its own line — its own net, its own tax, its own revenue account
 * — is what stops it being netted into either of those, which is where a taxable supply of the platform's
 * own would otherwise disappear.
 *
 * The tax is the gross minus the net, never the net times the rate. The two produce the same cent on 5.00
 * at 19% but not on every amount, and a fee that did not sum back to its own gross is a rounding error the
 * books cannot reconcile.
 */
final readonly class FeeLine
{
    public function __construct(
        /** What the payer is charged, tax included. */
        public Money $gross,
        /** The supply without tax. */
        public Money $net,
        /** The tax, as the difference from the gross. */
        public Money $tax,
        /** The country whose rate applied — the place of the supply, not the payer's seat. */
        public string $placeOfSupply,
    ) {}
}
