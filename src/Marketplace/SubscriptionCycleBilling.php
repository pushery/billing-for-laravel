<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\ServicePeriod;
use Pushery\Billing\ValueObjects\SupplyTaxCharacteristics;

/**
 * One billing cycle, one document — the caller the period machinery never had.
 *
 * ## What was missing, and it was not arithmetic
 *
 * Every part of this existed and none of it was reached for a subscription. A line could state the stretch
 * it covers and both e-invoice writers emit it; the receipt tier was decided per document from its own
 * gross; `settlement_period` was a column; the tax point could be frozen. Nothing produced a document PER
 * CYCLE, so all of it applied only to one-off purchases and a subscription had no part-supply at all.
 *
 * ## Why the tier is decided here, per document
 *
 * This is the point of cutting a term into periods rather than billing it once. A monthly subscription at
 * 11.90 stays far under the small-value threshold and may be issued as a simplified receipt carrying NO
 * buyer identity — which is the anonymity the fan was promised. Billed as one annual document the same
 * contract crosses that threshold and the promise is gone. So the tier is asked of each period's own gross,
 * never of what the contract is worth, and this class does not accept a tier from its caller for exactly
 * that reason.
 *
 * ## Repeating is normal, and a second document is not
 *
 * Payment events are redelivered; a billing run gets retried. A cycle that was already documented returns
 * ITS document rather than drawing a second number — the period's own key is what makes the repeat
 * recognizable, and it is stored on the row rather than recomputed, so a later change to how a key is
 * spelled cannot orphan the documents already written under the old spelling.
 *
 * The issuer asks the same question again before it writes, and that is not the same question twice over.
 * The issuer's is the one that HOLDS, for every caller including those that never come through here. This
 * one is the earlier and cheaper answer: it returns before a tier is resolved at all, so a repeat cannot be
 * turned into a failure by a threshold that has moved since the cycle was first billed.
 */
final readonly class SubscriptionCycleBilling
{
    public function __construct(
        private FanReceiptIssuer $receipts,
        private FanReceiptTierResolver $tiers,
    ) {}

    /**
     * The document for one cycle, issuing it if this cycle has none yet.
     *
     * @param  ServicePeriod  $period  the cycle, carrying the share of the term that belongs to it
     * @param  bool  $isDomestic  whether the small-value rules of the seller's own country apply
     * @param  ?CarbonImmutable  $issuedOn  when the document is dated; the period's start when not given
     */
    public function issueFor(
        Model $buyer,
        ServicePeriod $period,
        int $taxRateBps,
        bool $isDomestic,
        ?CarbonImmutable $issuedOn = null,
        ?string $chargeReference = null,
        ?SupplyTaxCharacteristics $characteristics = null,
    ): InvoiceRecord {
        $existing = $this->documentFor($buyer, $period);

        if ($existing instanceof InvoiceRecord) {
            return $existing;
        }

        // Named throughout, like `RoutedPayment::issueBuyerDocument()` has always called the same method.
        // This call passed eleven POSITIONAL arguments until the signature was narrowed, which is the form
        // worth naming here because nothing about it looked wrong: a parameter inserted in its middle would
        // have shifted every argument after it in silence, and two of the pairs it could have shifted past
        // were type-compatible — `?CarbonImmutable $deliveredOn` against `CarbonImmutable $soldOn`,
        // `?string $chargeReference` against `?string $provider`. The statics cannot tell those apart, and a
        // wrongly dated document is a tax error rather than a display one.
        //
        // The characteristics travel as ONE argument now, and the caller's is passed straight through. That
        // is a widening as well as a tidying: this lane could reach three of the eight and now reaches all
        // of them, so a cycle that knows its delivery date or its exemption can finally say so. Null stays
        // null — a caller with nothing to state writes the columns it always wrote.
        return $this->receipts->issue(
            buyerOwner: $buyer,
            // From THIS period's gross. Never the term's — see the note above; it is the whole reason the
            // term is cut up.
            tier: $this->tiers->tierFor($period->amount, $isDomestic, false),
            gross: $period->amount,
            taxRateBps: $taxRateBps,
            soldOn: $issuedOn ?? $period->from,
            chargeReference: $chargeReference,
            // No buyer block. It is not passed at all rather than passed as null — the parameter already
            // defaults to null and the toolchain removes the redundant argument — but the reason is worth
            // keeping: a cycle receipt is a simplified document, and a buyer's details belong only on a full
            // invoice.
            period: $period,
            characteristics: $characteristics,
        );
    }

    /**
     * The documents for a whole schedule, in order.
     *
     * Idempotent as a whole because it is idempotent per cycle: a run that failed halfway through resumes
     * without duplicating what it already wrote.
     *
     * @param  list<ServicePeriod>  $schedule
     * @return list<InvoiceRecord>
     */
    public function issueSchedule(
        Model $buyer,
        array $schedule,
        int $taxRateBps,
        bool $isDomestic,
        ?string $chargeReference = null,
        ?SupplyTaxCharacteristics $characteristics = null,
    ): array {
        return array_map(
            fn (ServicePeriod $period): InvoiceRecord => $this->issueFor(
                $buyer,
                $period,
                $taxRateBps,
                $isDomestic,
                chargeReference: $chargeReference,
                characteristics: $characteristics,
            ),
            $schedule,
        );
    }

    /**
     * The single document a PREPAID term owes, dated in the month the money arrived.
     *
     * ## Why this is not twelve documents
     *
     * Where tax arises on receipt, it arises when the money does — for the whole term, including the eleven
     * months not yet supplied. Billing that as twelve monthly documents would spread one tax liability across
     * a year it does not belong to, and every one of those documents would be individually plausible.
     *
     * ## And why it changes the tier
     *
     * This is the same threshold that makes a monthly subscription anonymous, read the other way round. One
     * document for the whole term is measured against the term's gross, so a term large enough crosses into
     * a document that must name its buyer. That is not a regression to route around: it is what issuing one
     * document MEANS, and the alternative — a small-value receipt for an amount above the limit — would be a
     * document making a claim it is not entitled to make.
     *
     * The caller decides which of the two shapes applies, because whether a term was prepaid is a fact about
     * the payment and this class is not told about payments. What it guarantees is that each shape is
     * internally right.
     *
     * @param  ServicePeriod  $term  the whole term as one stretch, carrying its whole price
     * @param  CarbonImmutable  $paidOn  when the money arrived — the month the tax belongs to
     */
    public function issuePrepaid(
        Model $buyer,
        ServicePeriod $term,
        int $taxRateBps,
        bool $isDomestic,
        CarbonImmutable $paidOn,
        ?string $chargeReference = null,
        ?SupplyTaxCharacteristics $characteristics = null,
    ): InvoiceRecord {
        $existing = $this->documentFor($buyer, $term);

        if ($existing instanceof InvoiceRecord) {
            return $existing;
        }

        return $this->receipts->issue(
            buyerOwner: $buyer,
            tier: $this->tiers->tierFor($term->amount, $isDomestic, false),
            gross: $term->amount,
            taxRateBps: $taxRateBps,
            // Dated to the RECEIPT, not to the start of what it covers. The document states the whole term as
            // its service period and the payment month as its date, which is exactly the situation.
            soldOn: $paidOn,
            chargeReference: $chargeReference,
            period: $term,
            characteristics: $characteristics,
        );
    }

    /** The buyer's document for this cycle, or null where none has been issued. */
    private function documentFor(Model $buyer, ServicePeriod $period): ?InvoiceRecord
    {
        return InvoiceRecord::query()
            ->where('owner_type', $buyer->getMorphClass())
            ->where('owner_id', $buyer->getKey())
            ->where('settlement_period', $period->key())
            // Narrowed to the buyer's own series. A creator's settlement for the same month carries a period
            // too, and matching on the period alone would hand a buyer's billing run the creator's document.
            ->where('document_series', DocumentSeries::BuyerReceipt->value)
            ->first();
    }
}
