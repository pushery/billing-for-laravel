<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use InvalidArgumentException;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * How much of a merchant's share comes back when a buyer is refunded.
 *
 * The rule is: recompute what the merchant would have been owed on what REMAINS of the sale, and claw back
 * the difference. Not a proportional share of the original payout — and the distinction is the whole reason
 * this class exists rather than a multiplication at the call site.
 *
 * With a percentage-only fee the two agree, which is why the mistake survives review. Add a fixed component
 * and they part company: a 100.00 sale at 10% plus 1.00 flat pays out 89.00, and half of it refunded leaves
 * a 50.00 sale that would have paid out 44.00 — so 45.00 comes back, not 44.50. The pro-rata figure is
 * short by half the flat fee, on every partial refund, forever, and both numbers look entirely reasonable.
 *
 * The fee is likewise recomputed on the remainder rather than halved, because a fixed component is not
 * halved by a half refund: the platform performed the handling once.
 */
final readonly class ClawbackCalculator
{
    /**
     * What comes back from the merchant, and what the platform returns of its own commission.
     *
     * @param  Money  $refund  what the buyer is being given back now, on top of anything already refunded
     * @return array{Money, Money} [merchantClawback, feeReturned]
     */
    public function forRefund(MerchantCharge $charge, PlatformFee $fee, Money $refund): array
    {
        if ($refund->currency !== $charge->currency) {
            throw new InvalidArgumentException(
                "A refund in {$refund->currency} cannot be applied to a charge in {$charge->currency}."
            );
        }

        if ($refund->isNegative()) {
            throw new InvalidArgumentException('A refund cannot be negative.');
        }

        $gross = $charge->gross();

        // What the buyer will have been given back once this refund lands, capped at what they paid.
        $refundedAfter = new Money(
            min($charge->refunded_minor + $refund->minorUnits, $gross->minorUnits),
            $charge->currency,
        );

        $remainingSale = $gross->minus($refundedAfter);

        // The heart of it: the merchant's share of what is LEFT, computed from scratch.
        //
        // On the SAME BASE the sale was made on, which the row carries. The commission is a net rate, so
        // the remainder's commission is taken on the remainder's net — and the rate for that is frozen,
        // never read from today's configuration. A sale made under one basis and reversed under another
        // does not return what was taken: the two figures differ by the commission on the buyer's tax, and
        // the difference is silent in whichever direction the bases happen to disagree.
        //
        // A row that predates the frozen rate answers with the payment itself, which is what those rows
        // actually did. That reading lives on the model, once, so this and every other reader cannot part
        // company over it.
        $feeOnRemainder = $fee->of($charge->commissionBase($remainingSale));

        // The difference, not a second split, so the two sides of the remainder sum back to it exactly.
        $payoutOnRemainder = $remainingSale->minus($feeOnRemainder);

        // What the merchant currently holds, less what they would hold on the remaining sale.
        $merchantHolds = new Money(
            $charge->net_minor - $charge->transfer_reversed_minor,
            $charge->currency,
        );
        $clawback = $merchantHolds->minus($payoutOnRemainder);

        // Likewise for the platform's own commission: what it kept, less what it would keep now.
        $platformHolds = new Money(
            $charge->fee_minor - $charge->fee_refunded_minor,
            $charge->currency,
        );
        $feeReturned = $platformHolds->minus($feeOnRemainder);

        return [$this->floorAtZero($clawback), $this->floorAtZero($feeReturned)];
    }

    /**
     * A reversal is never negative.
     *
     * A negative result would mean paying the merchant MORE because a buyer was refunded, which is not a
     * correction of anything. It can arise from a charge whose totals were moved by another path, and the
     * honest answer there is to reverse nothing rather than to invent a payment.
     */
    private function floorAtZero(Money $amount): Money
    {
        return $amount->isNegative() ? new Money(0, $amount->currency) : $amount;
    }
}
