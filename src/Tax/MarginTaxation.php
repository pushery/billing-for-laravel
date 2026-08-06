<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\ValueObjects\Money;

/**
 * The tax on a resale, computed from the difference rather than the price.
 *
 * ## The tax is inside the margin, not added to it
 *
 * The buyer pays the sale price and nothing else. Whatever tax is owed is already contained in the
 * difference between what the reseller paid and what they charged — so it is extracted from the margin, not
 * calculated on top of it. Adding it would produce a figure nobody paid and, worse, one the seller would owe
 * anyway if it ever reached a document.
 *
 * ## A loss is a zero, never a refund
 *
 * Sold for less than it cost, the difference is negative. The base is then zero — not a negative base, which
 * would net against other sales and hand back tax on a transaction that produced none. Losses are the normal
 * case in second-hand goods, so this is not an edge.
 *
 * ## One rate, and never the reduced one
 *
 * The margin is taxed at the standard rate whatever the goods are. A reduced rate exists for the *thing*
 * being sold; this taxes the *dealing*, and applying the goods' own reduced rate here would under-declare
 * every reduced-rate resale — invisibly, because each individual document would look consistent.
 */
final readonly class MarginTaxation
{
    /**
     * The tax contained in a resale's margin.
     *
     * @param  Money  $purchase  what the reseller paid for the goods
     * @param  Money  $sale  what they charged
     * @param  int  $standardRateBps  the standard rate, from the jurisdiction — never the goods' own
     */
    public function taxOn(Money $purchase, Money $sale, int $standardRateBps): Money
    {
        $margin = $this->marginOn($purchase, $sale);

        if ($margin->isZero()) {
            return $margin;
        }

        [, $tax] = $margin->baseFromMarkup($standardRateBps);

        return $tax;
    }

    /** The difference, floored at zero — a loss produces no base rather than a negative one. */
    public function marginOn(Money $purchase, Money $sale): Money
    {
        $margin = $sale->minus($purchase);

        return $margin->isNegative() ? Money::zero($sale->currency) : $margin;
    }
}
