<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a single line of a local order represents.
 *
 * An order sums its items to a total, and the type says what each line is so the invoice, the tax layer and
 * the credit-note path can treat them correctly — a discount and a credit both reduce the total but are not
 * the same thing (one is a coupon on the sale, the other an offset from a balance the customer already
 * holds).
 */
enum OrderItemType: string
{
    /** A recurring plan charge for the cycle. */
    case Subscription = 'subscription';

    /** Metered usage priced for the cycle. */
    case Usage = 'usage';

    /** A one-time add-on bought alongside the cycle. */
    case Addon = 'addon';

    /** A coupon reduction on the sale (a negative line). */
    case Discount = 'discount';

    /** An offset drawn from the customer's credit balance (a negative line). */
    case Credit = 'credit';

    /**
     * A late fee from the dunning ladder: compensation for the delay, not the price of anything supplied.
     *
     * It stands in an order of its own. Its document is outside the scope of VAT, and EN 16931 lets a document
     * that states that category state no other (BR-O-11), so the fee cannot share an invoice with a cycle.
     */
    case LateFee = 'late_fee';
}
