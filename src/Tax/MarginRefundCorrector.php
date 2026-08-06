<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a refund of a margin-taxed sale gives back in tax.
 *
 * ## Not the tax on the refund
 *
 * The ordinary correction takes back the tax that was charged on the amount being returned. Here that would
 * be wrong by an order of magnitude: the tax was never on the price, it was on the margin. A 500 sale of
 * goods bought for 400 carried tax on 100. Correcting on the price would hand back five times what was ever
 * paid — and it would look like an ordinary refund the whole way through.
 *
 * ## A partial refund is NOT proportional, and this is the part worth reading twice
 *
 * The instinct is to reduce the margin by the refunded share: refund a fifth of the price, correct a fifth
 * of the margin. That is wrong, and it is wrong in the direction that under-corrects.
 *
 * The margin is the sale price less what the seller paid for the goods. A partial refund lowers the sale
 * price; it does not lower what the seller paid, which already happened. So the margin falls by the FULL
 * refunded amount, not by a share of it — until it reaches zero, at which point the seller is selling at or
 * below cost and there is no margin left to tax.
 *
 * Refund 50 of that same sale and the margin goes from 100 to 50: the correction is on 50, not on 10.
 *
 * ## Never below zero
 *
 * Refund more than the margin and the margin is zero, not negative. A negative margin would produce tax
 * flowing back on a transaction that never produced any — and refunds larger than the margin are ordinary
 * here, because goods are frequently sold at a small markup and returned in full.
 */
final readonly class MarginRefundCorrector
{
    /**
     * The tax to give back, computed on the margin the sale was actually taxed on.
     *
     * @param  Money  $refunded  what is being returned to the buyer
     * @param  int  $standardRateBps  the rate the margin was taxed at — the standard one, never the goods'
     */
    public function correctionFor(InvoiceRecord $sale, Money $refunded, int $standardRateBps): Money
    {
        $base = $this->correctedBase($sale, $refunded);

        if ($base->isZero()) {
            return $base;
        }

        [, $tax] = $base->baseFromMarkup($standardRateBps);

        return $tax;
    }

    /**
     * How much of the margin the refund removes.
     *
     * @return Money the amount the taxable base is reduced by, never more than the margin itself
     */
    public function correctedBase(InvoiceRecord $sale, Money $refunded): Money
    {
        $currency = $refunded->currency;
        $margin = Money::of(max(0, $sale->margin_minor ?? 0), $currency);

        // The refund lowers the sale price by its own full amount; what the seller paid for the goods is
        // unchanged. So the margin falls by the refund, floored at nothing left to tax.
        return Money::of(min($refunded->minorUnits, $margin->minorUnits), $currency);
    }

    /** What remains taxable after the refund — the margin the seller is left with. */
    public function remainingMargin(InvoiceRecord $sale, Money $refunded): Money
    {
        $currency = $refunded->currency;
        $margin = max(0, $sale->margin_minor ?? 0);

        return Money::of(max(0, $margin - $refunded->minorUnits), $currency);
    }
}
