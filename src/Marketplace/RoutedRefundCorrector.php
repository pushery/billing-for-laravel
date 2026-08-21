<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\ValueObjects\ChainCorrection;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * A refund on a routed sale, carried through to the correcting document.
 *
 * The three parts of this were built separately — the arithmetic over both chain links, the document a
 * correction has to be, and the link from a charge back to the settlement issued for it — and this is what
 * makes them one act. A refund that computed the correction and issued nothing, or issued a document from
 * figures computed some other way, would be the same failure in two different shapes.
 *
 * ## Everything comes from the FROZEN sale, nothing from today
 *
 * The buyer's gross, the rate it was taxed at, the terms it was priced under, the standing the merchant had:
 * every input is read off the settlement that was issued, not resolved again now. A rate cut, a merchant who
 * has since registered, a repriced commission — each would otherwise rewrite a past sale into one nobody
 * made, and the resulting document would still add up. That is the whole reason those fields are frozen, and
 * this is the caller they were frozen for.
 *
 * ## Idempotent because the amount is, not because a flag says so
 *
 * The refund passed in is what actually moved, which the routed ledger reports after capping it against what
 * is left. A redelivered webhook moves nothing, so there is nothing to correct and no document is issued —
 * no separate claim to keep in step with the money.
 */
final readonly class RoutedRefundCorrector
{
    public function __construct(
        private RefundCascade $cascade,
        private SettlementCorrectionIssuer $issuer,
        private Repository $config,
    ) {}

    /**
     * Correct the settlement for a charge by what a refund actually moved.
     *
     * Both sides are corrected in one call, because they are one event seen from two places — the same
     * reason the arithmetic covers both links. A caller that could correct one and not the other would
     * eventually do exactly that.
     *
     * @param  Money  $refunded  what this refund actually returned to the buyer, after capping
     * @return array{?ChainCorrection, ?InvoiceRecord, ?InvoiceRecord} the correction, the merchant-side
     *                                                                 document, and the buyer-side one
     *
     * The reason defaults to the ordinary case — the money went back. A chargeback passes the other one: the
     * amounts are identical, and only this says whether a later payment reopens the correction.
     *
     * The attempt is the reversal row these documents document, where the caller holds one. It is optional
     * because two of the three paths that correct a chain genuinely have none: a prepaid term cancellation
     * opens no attempt, and the chargeback effect runs in a different unit of work from the reversal. Passing
     * null there records that honestly rather than leaving a link nobody can tell apart from an unset one.
     */
    public function correct(
        MerchantCharge $charge,
        Money $refunded,
        CreatorTaxStatus $statusAtSupply,
        CarbonImmutable $correctedOn,
        TaxBaseChangeReason $reason = TaxBaseChangeReason::Repaid,
        ?RefundAttempt $attempt = null,
    ): array {
        if (! $refunded->isPositive()) {
            return [null, null, null];
        }

        // The charge knows WHOSE reference it is, so the lookup is given both halves of the key. Passing the
        // reference alone would work on this installation and quietly match a stranger's document on one with
        // a second driver — and the correction would then reverse a sale that was never refunded.
        $settlement = $this->issuer->settlementFor($charge->charge_reference, $charge->provider);

        if (! $settlement instanceof InvoiceRecord) {
            return [null, null, null];
        }

        $saleGross = $this->saleGross($settlement, $refunded->currency);

        $correction = $this->cascade->forRefund(
            $settlement->supply_regime ?? SupplyRegime::CommissionChain,
            $saleGross,
            // What the buyer had already been given back BEFORE this refund. The ledger's running total
            // already includes this one by the time it is applied, so the earlier state is the difference.
            $this->refundedBefore($charge, $refunded),
            $refunded,
            $this->frozenCommission($settlement),
            $statusAtSupply,
            $settlement->tax_rate_bps ?? 0,
            $settlement->tax_rate_bps ?? 0,
            // Handed to the cascade as well, not only to the issuers below. It was reaching the documents
            // and not the arithmetic that decides WHICH documents exist, so an uncollectible loss produced
            // a creator-side correction anyway — the one thing the reason is carried to prevent.
            $reason,
        );

        $receipt = $this->issuer->buyerReceiptFor($charge->charge_reference, $charge->provider);

        return [
            $correction,
            $this->issuer->issue($settlement, $correction, $correctedOn, $reason, $attempt),
            // ON ONE LINE for the same reason as SubscriptionOverview: the continuation line of a
            // multi-line ternary is counted executable by php-code-coverage 14 and never recorded hit.
            $receipt instanceof InvoiceRecord ? $this->issuer->issueForBuyer($receipt, $correction, $correctedOn, $reason, $attempt) : null,
        ];
    }

    /**
     * What the buyer paid for the whole sale, frozen on the settlement at issue.
     *
     * Not recomputed from the payout and a rate: the fan gross is the one figure that says what the buyer
     * actually handed over, and reconstructing it would reintroduce the rounding the settlement already
     * resolved once.
     */
    private function saleGross(InvoiceRecord $settlement, string $currency): Money
    {
        return new Money((int) $settlement->fan_gross_minor, $currency);
    }

    /**
     * The buyer's refunds before this one.
     *
     * Read as a subtraction from the running total rather than from a separate column, because the running
     * total is what caps the refund in the first place — deriving the earlier state from it is the only way
     * the two cannot disagree.
     */
    private function refundedBefore(MerchantCharge $charge, Money $refunded): Money
    {
        return new Money(max(0, $charge->refunded_minor - $refunded->minorUnits), $refunded->currency);
    }

    /**
     * The commission terms the sale was priced under.
     *
     * A settlement written before those were frozen carries none, and a zero commission is the honest read:
     * it recomputes the remainder as if the platform took nothing, which understates the clawback rather
     * than inventing a rate the sale may never have had.
     *
     * The rounding direction is read from the document too, for the same reason the rate is. It used to be
     * assumed, and on an installation that hands the odd minor unit the other way the correction came back a
     * cent off the sale it was correcting — on every uneven split, with both documents adding up. An older
     * settlement that never recorded it falls back to what this installation does today, which is the
     * closest thing to the truth still available.
     */
    private function frozenCommission(InvoiceRecord $settlement): PlatformFee
    {
        return new PlatformFee(
            $settlement->commission_bps ?? 0,
            $settlement->commission_flat_minor ?? 0,
            $settlement->commission_residual ?? $this->configuredResidual(),
        );
    }

    /**
     * What this installation does with the odd minor unit today.
     *
     * Only reached for a settlement written before the direction was recorded. It is a guess, but it is the
     * closest one still available — and it is a far better guess than a constant, which would be wrong on
     * every installation configured the other way.
     */
    private function configuredResidual(): RoundingResidual
    {
        return RoundingResidual::fromConfigured($this->config->get('billing.marketplace.fee.rounding'))
            ?? RoundingResidual::ToPortion;
    }
}
