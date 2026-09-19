<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Exceptions\SettlementLineNotFound;
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

        // WHICH line of the settlement this charge is, where the settlement carries more than one.
        //
        // A collective document settles a month, so its header states no commission, no rate and no fan
        // gross — a month has one of none of those. Everything below reads exactly those off `$settlement`.
        // The line states them, and `lineSettling()` is the one place that lookup lives, because the
        // correcting document needs the same line for its frozen characteristics.
        //
        // Null for a per-transaction settlement, whose single line names no charge because there the header
        // IS the line. That is not a special case to branch on — the header answers, as it always has.
        $line = $settlement->lineSettling($charge->provider, $charge->charge_reference);

        if ($settlement->settlement_period !== null && ! $this->lineAnswersEverything($line)) {
            // Refused, and the condition is deliberately TOTAL rather than "no line names the charge".
            //
            // A line missing any ONE of the three produces a correction computed partly from zeros, and
            // each missing field fails differently while looking equally complete. An absent commission
            // books a reversal as if the platform kept nothing; an absent fan gross books it as 0.00
            // outright against a real sale.
            //
            // So the question is not whether a line was found but whether it can answer. A line written by
            // an older version answers no and refuses, which names the month to re-run instead of quietly
            // booking a wrong figure.
            throw SettlementLineNotFound::forCharge(
                (string) $settlement->number,
                $charge->provider,
                $charge->charge_reference,
            );
        }

        $saleGross = $this->saleGross($settlement, $refunded->currency, $line);

        $correction = $this->cascade->forRefund(
            $settlement->supply_regime ?? SupplyRegime::CommissionChain,
            $saleGross,
            // What the buyer had already been given back BEFORE this refund. The ledger's running total
            // already includes this one by the time it is applied, so the earlier state is the difference.
            $this->refundedBefore($charge, $refunded),
            $refunded,
            $this->frozenCommission($settlement, $line),
            $statusAtSupply,
            $this->frozenRate($settlement, $line),
            $this->frozenRate($settlement, $line),
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
    /**
     * @param  array<array-key, mixed>|null  $line  the settlement line this charge is, where one names it
     */
    private function saleGross(InvoiceRecord $settlement, string $currency, ?array $line = null): Money
    {
        // The LINE first, for the same reason as the commission and the rate: a month has no single fan
        // gross, so a collective header states none, and reading it there answers 0.00 for every
        // collectively settled sale.
        if ($line !== null && is_int($line['fan_gross_minor'] ?? null)) {
            return new Money($line['fan_gross_minor'], $currency);
        }

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
    /**
     * Whether a settlement line carries every input the correction reads off it.
     *
     * Checked as a set rather than field by field at each reader, because the readers are spread across the
     * cascade call and two private helpers — and a field checked at one and missing at another is a
     * correction half computed from the line and half from a header that states nothing.
     *
     * `commission_flat_minor` and `commission_residual` are NOT required. A fee with no fixed part is the
     * ordinary case and a zero there is a true statement about it; the residual has a configured fallback
     * the per-transaction path has always used for settlements written before the direction was recorded.
     * The three below have no true default: a missing commission rate is not a zero rate, and a missing fan
     * gross is not a sale of nothing.
     *
     * @param  array<array-key, mixed>|null  $line
     */
    private function lineAnswersEverything(?array $line): bool
    {
        if ($line === null) {
            return false;
        }

        return is_int($line['commission_bps'] ?? null)
            && is_int($line['tax_rate_bps'] ?? null)
            && is_int($line['fan_gross_minor'] ?? null);
    }

    /**
     * The rate the supply was taxed at, from the line where one answers for this charge.
     *
     * A collective header carries no rate, and a zero rate turns a correction of a taxed supply into a
     * correction of an untaxed one — arithmetically clean and wrong by the tax.
     *
     * @param  array<array-key, mixed>|null  $line
     */
    private function frozenRate(InvoiceRecord $settlement, ?array $line = null): int
    {
        if ($line !== null && is_int($line['tax_rate_bps'] ?? null)) {
            return $line['tax_rate_bps'];
        }

        return $settlement->tax_rate_bps ?? 0;
    }

    /**
     * @param  array<array-key, mixed>|null  $line  the settlement line this charge is, where one names it
     */
    private function frozenCommission(InvoiceRecord $settlement, ?array $line = null): PlatformFee
    {
        // The LINE first, where there is one. A collective document states no commission on its header, so
        // reading the header there would treat every collectively settled sale as having carried none.
        if ($line !== null) {
            return new PlatformFee(
                is_int($line['commission_bps'] ?? null) ? $line['commission_bps'] : 0,
                is_int($line['commission_flat_minor'] ?? null) ? $line['commission_flat_minor'] : 0,
                RoundingResidual::tryFrom(is_string($line['commission_residual'] ?? null) ? $line['commission_residual'] : '')
                    ?? $this->configuredResidual(),
            );
        }

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
