<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which side of a sale decides where it is taxed.
 *
 * Two rules, and the distinction is the only content classification that still changes an outcome: a
 * supply taxed where the BUYER is follows their country's rate and reports into that country, while one
 * taxed where the SELLER is carries the platform's own rate wherever the buyer happens to be.
 *
 * It is frozen onto a transaction rather than read from the product, because a product's classification can
 * change after it has been sold — and a correction that re-derived the rule would report a past sale into a
 * country it was never declared in.
 */
enum PlaceOfSupplyRule: string
{
    /** Taxed where the buyer is. */
    case Destination = 'destination';

    /** Taxed where the seller is, whatever the buyer's country. */
    case Domestic = 'domestic';
}
