<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Invoicing\ProratedTermRefund;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\ChainCorrection;
use Pushery\Billing\ValueObjects\Money;

/**
 * Canceling a prepaid term: what is owed back, handed to the correction path that already exists.
 *
 * A year paid in January and canceled after four months owes eight months. Two pieces answered that
 * already and nothing joined them: {@see ProratedTermRefund} knows how much, and {@see RoutedRefundCorrector}
 * knows how a refund becomes correcting documents on both links of the chain. This is the join, and it is
 * deliberately thin — every rule it needs lives in one of those two.
 *
 * ## Why it does NOT call the cascade directly
 *
 * The obvious shape is "compute the amount, hand it to RefundCascade" — and it is wrong. The cascade
 * computes a correction; it does not issue the documents, place them in the original's period, or read the
 * frozen sale. `RoutedRefundCorrector` does all three, and going around it would put a SECOND correction
 * path beside it: two places deciding document issuance, period assignment and which values are read frozen.
 * They would drift, and the drift would be a document that adds up and states the wrong period.
 *
 * ## Why the caller supplies the term, and this class does not look it up
 *
 * Nothing in this package stores "this subscription was sold as a twelve-month prepaid term, four of them
 * used". `ProratedTermRefund` and `SubscriptionPeriodSchedule` both TAKE those numbers. The tempting
 * substitute — derive them from a start date and today — is wrong the moment a cycle was shifted, paused or
 * swapped, and wrong silently. The consumer knows what it sold; this class refuses to guess.
 *
 * That is also why this is a service the consumer calls rather than a listener on a cancellation event. A
 * refund is a money movement, not a side effect of a status change: cancellations arrive from a webhook, an
 * admin action and the consumer's own UI, many of them owing nothing back (a cancellation at period end
 * simply runs out), and a listener would have to tell those apart at a point that no longer knows the
 * reason — with no idempotency key better than a subscription that can be canceled and resumed repeatedly.
 */
final readonly class PrepaidTermCancellation
{
    public function __construct(
        private RoutedRefundCorrector $corrector,
        private CreatorTaxStatusResolver $statuses,
    ) {}

    /**
     * Refund the unused part of a prepaid term and correct both links of the chain.
     *
     * @param  MerchantCharge  $charge  the routed charge the term was paid on
     * @param  Model  $merchant  the creator whose standing at the supply decides the inbound treatment
     * @param  Money  $term  what the buyer paid for the whole term, frozen at the sale
     * @param  int  $periodsUsed  periods consumed before the cancellation — 0 cancels before it starts
     * @param  int  $periodsInTerm  how many periods the term was sold as
     * @return array{0: ?ChainCorrection, 1: ?InvoiceRecord, 2: ?InvoiceRecord} the correction and the two
     *                                                                          documents, exactly as
     *                                                                          RoutedRefundCorrector::correct()
     *                                                                          reports them — passed through
     *                                                                          rather than reshaped, so the
     *                                                                          result has one description
     */
    public function cancel(
        MerchantCharge $charge,
        Model $merchant,
        Money $term,
        int $periodsUsed,
        int $periodsInTerm,
        ?CarbonImmutable $correctedOn = null,
    ): array {
        // The amount is ASKED FOR, never computed here. A second rounding rule is the divergence nobody
        // notices, because both numbers look reasonable — and on an uneven term the two answers differ by a
        // cent on every single one.
        $refund = ProratedTermRefund::unusedPortion($term, $periodsUsed, $periodsInTerm);

        // A term canceled at its very end owes nothing. Returning before the corrector keeps a zero out of
        // the document path entirely rather than relying on it to no-op: a correcting document for nothing
        // is still a document, and it would carry a number from a gapless series.
        if (! $refund->isPositive()) {
            return [null, null, null];
        }

        // Frozen at the supply, not read as of today — the same rule, and the same source, that the
        // chargeback path uses. A creator who has since registered for VAT must not retroactively change how
        // a term sold before that is corrected.
        //
        // ON ONE LINE, and not a style choice -- do not fold it back. php-code-coverage 14 counts the
        // CONTINUATION line of a multi-line ternary as executable and never records it as hit, so folded
        // this file measures 92.86% with the `: now()` arm as its only gap. The same trap is documented on
        // SubscriptionOverview::render(), measured both ways there on identical tests.
        $suppliedOn = $charge->settled_at instanceof Carbon ? CarbonImmutable::parse($charge->settled_at) : CarbonImmutable::now();

        return $this->corrector->correct(
            $charge,
            $refund,
            $this->statuses->statusAt($merchant, $suppliedOn),
            $correctedOn ?? CarbonImmutable::now(),
            // Money went back to the buyer. Not a write-off and not a dispute — both correct a different
            // set of links, and naming the reason here is what keeps that decision out of this class.
            TaxBaseChangeReason::Repaid,
        );
    }
}
