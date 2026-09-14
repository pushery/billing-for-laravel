<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

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
 *
 * ## Who calls it
 *
 * The consumer that issued the document. The package issues no margin-taxed document itself, so the refund of
 * one is the consumer's to correct, and this is the arithmetic it would otherwise have to get right alone.
 */
final readonly class MarginRefundCorrector
{
    /**
     * The tax to give back, computed on the margin the sale was actually taxed on.
     *
     * @param  Money  $refunded  what is being returned to the buyer
     * @param  int  $standardRateBps  the rate the margin was taxed at — the standard one, never the goods'
     *
     * @throws RuntimeException when the sale was not taxed on the margin, or carries no frozen margin
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
     *
     * @throws RuntimeException when the sale was not taxed on the margin, or carries no frozen margin
     */
    public function correctedBase(InvoiceRecord $sale, Money $refunded): Money
    {
        $margin = $this->frozenMargin($sale);

        // The refund lowers the sale price by its own full amount; what the seller paid for the goods is
        // unchanged. So the margin falls by the refund, floored at nothing left to tax.
        return Money::of(min($refunded->minorUnits, $margin), $refunded->currency);
    }

    /**
     * What remains taxable after the refund — the margin the seller is left with.
     *
     * @throws RuntimeException when the sale was not taxed on the margin, or carries no frozen margin
     */
    public function remainingMargin(InvoiceRecord $sale, Money $refunded): Money
    {
        return Money::of(max(0, $this->frozenMargin($sale) - $refunded->minorUnits), $refunded->currency);
    }

    /**
     * The margin frozen onto the sale, refused where there is none to read.
     *
     * Two refusals, and both used to be answers. A sale taxed on its price has no margin, and correcting it
     * here would give back a fraction of the tax it really stated. A margin-taxed sale with no margin frozen
     * onto it was read as a margin of zero, so its refund gave no tax back at all, with a figure that added
     * up. Neither can be repaired from in here: what the seller paid for the goods is a fact this system
     * never held.
     *
     * The second refusal has an ordinary cause, and the message names it. A reseller who works out the margin
     * over a whole period rather than item by item has no margin per sale to freeze. Their refund lowers the
     * period's total, which is a different computation rather than a missing value.
     */
    private function frozenMargin(InvoiceRecord $sale): int
    {
        $document = $sale->number ?? (string) $sale->id;

        if ($sale->taxation_basis?->taxesMarginOnly() !== true) {
            throw new RuntimeException(
                "Document {$document} was not taxed on the margin, so its refund is corrected on the tax it "
                .'states, not here. Corrected on a margin, it would give back a fraction of what was charged.'
            );
        }

        if ($sale->margin_minor === null) {
            throw new RuntimeException(
                "Document {$document} is taxed on the margin but carries no frozen margin, so its refund cannot "
                .'be corrected per sale. Freeze margin_minor when the document is issued. Where the margin is '
                ."worked out over a whole period instead, the refund lowers that period's total and is corrected there."
            );
        }

        return max(0, $sale->margin_minor);
    }
}
