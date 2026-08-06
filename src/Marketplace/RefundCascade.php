<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use InvalidArgumentException;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\ValueObjects\ChainCorrection;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * A refund, resolved into the correction it makes to every link of a commission-chain sale.
 *
 * A refund is a change to the taxable base, and in a chain it changes every base the sale created — the
 * platform's supply to the buyer AND the creator's supply to the platform. Correcting one and not the other
 * is not a partial job, it is a wrong one: the input tax deducted on the creator's link stays deducted, and
 * nothing about the books looks unusual afterwards.
 *
 * ## Recomputed on the remainder, never scaled
 *
 * Every figure comes from asking what the sale WOULD have been had it always been the smaller sale, and
 * subtracting. Never from taking a share of the original. With a percentage-only commission the two agree,
 * which is exactly why the difference survives review; add a fixed component and they part company on every
 * partial refund, in the platform's disfavor, permanently. Recomputing also makes repetition harmless: two
 * halves and one whole reach the same place, and a redelivered webhook that re-states the same cumulative
 * total corrects nothing a second time.
 *
 * ## The tax treatment is asked twice, not reasoned about once
 *
 * Both the original and the remaining state of the creator's link are resolved through the same tax matrix
 * that priced the sale. Nothing here re-decides whether a creator's supply states tax, exempts it or
 * reverses it — a second implementation of that decision would be a second thing to keep right, and the
 * status vocabulary is exactly where such drift hides.
 *
 * ## The standing is the one FROZEN at the supply
 *
 * The caller passes the creator's standing as it was when the supply happened, not as it is now, and this is
 * load-bearing rather than pedantic: a creator who crossed a registration threshold between the sale and the
 * refund has a different standing today, and correcting the old document by today's standing would state a
 * tax reversal that the original document never claimed. The correction undoes what was issued; only the
 * original's own terms can say what that was.
 *
 * ## When it applies
 *
 * The commission chain alone. An intermediation sale has no second link to correct — the platform arranged
 * someone else's supply and invoiced its own fee — so its refund corrects that fee invoice instead, which is
 * a different document and a different calculation.
 *
 * Nothing national appears here. The rates enter as basis points and the correcting document's series,
 * wording and statutory citations live downstream in the jurisdiction profile; a consumer elsewhere gets the
 * same two-link arithmetic with their own rates and reads no foreign statute.
 */
final readonly class RefundCascade
{
    public function __construct(private InboundTaxMatrix $matrix = new InboundTaxMatrix) {}

    /**
     * @param  Money  $saleGross  what the buyer paid for the whole sale, frozen at the sale
     * @param  Money  $refundedBefore  what the buyer had already been given back before this refund
     * @param  Money  $refundNow  what the buyer is being given back now
     * @param  PlatformFee  $commission  the platform's take, as it applied to this sale
     * @param  CreatorTaxStatus  $creatorStatusAtSupply  the creator's standing WHEN THE SUPPLY HAPPENED
     * @param  int  $outboundRateBps  the rate of the platform's own supply to the buyer
     * @param  int  $inboundRateBps  the rate of the creator's supply to the platform
     * @param  TaxBaseChangeReason  $reason  why the base is changing, which decides whether the CREATOR's
     *                                       link is corrected at all — see below
     */
    public function forRefund(
        SupplyRegime $regime,
        Money $saleGross,
        Money $refundedBefore,
        Money $refundNow,
        PlatformFee $commission,
        CreatorTaxStatus $creatorStatusAtSupply,
        int $outboundRateBps,
        int $inboundRateBps,
        TaxBaseChangeReason $reason = TaxBaseChangeReason::Repaid,
    ): ChainCorrection {
        $this->guard($regime, $saleGross, $refundedBefore, $refundNow);

        // Both sides are the sale AS IT STOOD — before this refund and after it — never the whole sale
        // against the remainder. The distinction only shows up on the second partial refund, where the
        // latter would re-correct the first one: the same document issued twice and the payout reclaimed
        // past what was ever paid. Capped at the sale, because a cumulative total that overshoots corrects
        // the sale to zero rather than past it.
        $refundedAlready = $this->cappedAt($refundedBefore, $saleGross);
        $refundedAfter = $this->cappedAt($refundedBefore->plus($refundNow), $saleGross);
        $refundApplied = $refundedAfter->minus($refundedAlready);

        // The outbound split, taken as base-and-difference so the two always sum back to what was paid.
        [$netBefore, $taxBefore] = $saleGross->minus($refundedAlready)->baseFromMarkup($outboundRateBps);
        [$netAfter, $taxAfter] = $saleGross->minus($refundedAfter)->baseFromMarkup($outboundRateBps);

        $treatmentBefore = $this->treatment($creatorStatusAtSupply, $netBefore, $commission, $inboundRateBps);
        $treatmentAfter = $this->treatment($creatorStatusAtSupply, $netAfter, $commission, $inboundRateBps);

        $expenseBefore = $this->expenseOf($treatmentBefore);
        $expenseAfter = $this->expenseOf($treatmentAfter);

        // The commission is the difference between the two links' bases — computed here as exactly that,
        // rather than by applying the rate a second time, so it can never disagree with the two links it
        // sits between. It is the figure the reconciliation in ChainCorrection checks against.
        $commissionReturned = $netBefore->minus($expenseBefore)->minus($netAfter->minus($expenseAfter));

        return new ChainCorrection(
            buyerRefund: $refundApplied,
            outboundNet: $netBefore->minus($netAfter),
            outboundTax: $taxBefore->minus($taxAfter),
            inboundExpense: $expenseBefore->minus($expenseAfter),
            inboundInputTax: $this->statedTaxOf($treatmentBefore)->minus($this->statedTaxOf($treatmentAfter)),
            reverseChargeTax: $this->reversedTaxOf($treatmentBefore, $inboundRateBps)
                ->minus($this->reversedTaxOf($treatmentAfter, $inboundRateBps)),
            merchantClawback: $treatmentBefore->payoutAmount->minus($treatmentAfter->payoutAmount),
            commissionReturned: $commissionReturned,
            // The creator's link is corrected only where the CONSIDERATION went back. On an uncollectible
            // loss it did not: the creator supplied what they promised, and the money is gone to a stolen
            // card rather than returned to a customer. Issuing a correcting document against them there
            // would reduce the turnover of somebody who did nothing wrong — the platform's loss written
            // onto the creator's tax return, on a document they receive and cannot explain.
            //
            // Only the DOCUMENT is suppressed here. Whether the platform recovers the money from the
            // creator under its contract is a separate claim and a separate path; this class answers what
            // the tax base did, and on this branch the creator's base did not move.
            inboundDocument: $reason === TaxBaseChangeReason::Uncollectible ? null : $treatmentBefore->document,
        );
    }

    private function guard(SupplyRegime $regime, Money $saleGross, Money $refundedBefore, Money $refundNow): void
    {
        if ($regime !== SupplyRegime::CommissionChain) {
            throw new InvalidArgumentException(
                'Only a commission-chain sale has a second link to correct; an intermediation refund '
                .'corrects the platform\'s own fee invoice, which is a different document entirely.'
            );
        }

        foreach (['refundedBefore' => $refundedBefore, 'refundNow' => $refundNow] as $name => $amount) {
            if ($amount->currency !== $saleGross->currency) {
                throw new InvalidArgumentException(
                    "A {$name} in {$amount->currency} cannot correct a sale in {$saleGross->currency}."
                );
            }

            if ($amount->isNegative()) {
                throw new InvalidArgumentException("A {$name} cannot be negative.");
            }
        }

        if ($saleGross->isNegative()) {
            throw new InvalidArgumentException('A sale cannot have a negative gross.');
        }
    }

    private function cappedAt(Money $amount, Money $ceiling): Money
    {
        return $amount->greaterThan($ceiling) ? $ceiling : $amount;
    }

    private function treatment(
        CreatorTaxStatus $status,
        Money $net,
        PlatformFee $commission,
        int $rateBps,
    ): InboundTaxTreatment {
        return $this->matrix->resolve(SupplyRegime::CommissionChain, $status, $net, $commission, $rateBps);
    }

    /**
     * The cost the creator's link represents: what they are paid, less any tax paid through with it.
     *
     * Taken as a subtraction rather than read from a field because the payout is the ONE figure the tax
     * treatment reports gross of its own tax — it is what leaves the bank — while the expense is what the
     * platform booked. Reading the payout as the expense would inflate the cost by the tax on every
     * tax-stating supply, and deflate nothing anywhere else, so the error would look like a rounding drift.
     */
    private function expenseOf(InboundTaxTreatment $treatment): Money
    {
        return $treatment->payoutAmount->minus($treatment->taxAmount);
    }

    /** The tax the creator's document actually STATED — the only tax that yielded a deduction. */
    private function statedTaxOf(InboundTaxTreatment $treatment): Money
    {
        return $treatment->showsTax ? $treatment->taxAmount : Money::zero($treatment->payoutAmount->currency);
    }

    /**
     * The tax a foreign creator's supply reverses onto the platform.
     *
     * The treatment carries the fact but not the amount — a reverse-charged document states no tax, which is
     * the whole point of it — so the amount is the one thing this has to compute: the recipient assesses the
     * rate on what they paid.
     */
    private function reversedTaxOf(InboundTaxTreatment $treatment, int $rateBps): Money
    {
        if (! $treatment->reverseChargeToRecipient) {
            return Money::zero($treatment->payoutAmount->currency);
        }

        return $this->expenseOf($treatment)->proportion($rateBps, 10_000);
    }
}
