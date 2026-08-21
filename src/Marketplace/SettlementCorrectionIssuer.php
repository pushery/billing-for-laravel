<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Exceptions\InvalidInvoiceCorrection;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\Tax\FreezeExchangeRateOnDocument;
use Pushery\Billing\ValueObjects\ChainCorrection;
use Pushery\Billing\ValueObjects\Money;

/**
 * Turns a correction into the document that carries it.
 *
 * The arithmetic of a refund on a routed sale is settled elsewhere ({@see RefundCascade}); what happens here
 * is the part that has to be a DOCUMENT — a numbered, referenced, immutable record the creator and a tax
 * authority can both read. A correction that exists only as a figure in a ledger corrects nothing anybody can
 * be shown.
 *
 * ## Booked in the month it happened, never in the original's
 *
 * The correcting document carries the date the refund happened, not the date of the sale. Both are defensible
 * arithmetic and only one is lawful: the original document stays exactly as issued, and the correction stands
 * beside it in its own period. Back-dating into the original's period would reopen a period that has been
 * declared, and would make two documents describe one month differently.
 *
 * ## Its own series, and a reference that is not optional
 *
 * A correction draws from the correction series paired to the original's, so a document and its correction
 * never share a number and a reader can tell them apart at a glance. And it names what it corrects — an
 * amendment without that reference is not a valid document at all, which is why the reference is read from
 * the original rather than passed in: a caller who could supply it could also supply the wrong one.
 *
 * ## Positive amounts
 *
 * The correcting document states magnitudes, and its ROLE inverts the meaning. A negative invoice is not a
 * thing; a document that says "this much less" is. The cascade already reports reductions as positive, so
 * they land here unchanged rather than being negated on the way in and back out.
 */
final readonly class SettlementCorrectionIssuer
{
    /**
     * The rate carrier is REQUIRED, and that is the point rather than an oversight.
     *
     * Its sibling in `SelfBillingEngine` was optional with a default, and the container handed that engine
     * `null` without ever attempting to resolve it -- `Container::resolveClass()` returns the default up
     * front for any class it has no binding for. The seam existed, resolved fine in isolation, and never
     * fired. A required parameter cannot fail that way: the container must produce one or say why.
     */
    /**
     * The disclosure guard is REQUIRED for the same reason the rate carrier is, and its absence here was the
     * live half of that lesson. The original settlement passes the whitelist before a number is drawn; its
     * CORRECTION did not pass it at all, because this class had no way to ask. So a creator whose standing
     * never permitted stated tax could still receive a correcting document that states some.
     */
    public function __construct(
        private DocumentNumberAllocator $numbers,
        private FreezeExchangeRateOnDocument $exchangeRates,
        private CreatorTaxDisclosureGuard $disclosure,
    ) {}

    /**
     * The merchant-side settlement issued for one routed transaction, or null when none was.
     *
     * The lookup a refund arrives needing: it knows the charge, and the correction it owes has to find the
     * document issued for it. Two things narrow it, and both are load-bearing.
     *
     * Originals only — a correction of that document names the same charge, and returning one would have a
     * later correction correct its own predecessor's correction.
     *
     * And the SERIES, because one routed sale produces documents on both sides and both name the charge: the
     * buyer's receipt and the merchant's settlement. Matching on the charge alone would return whichever was
     * written first, and correcting a buyer's receipt with the merchant's figures produces a document that
     * is internally consistent and describes the wrong party's supply.
     */
    public function settlementFor(string $chargeReference, ?string $provider = null): ?InvoiceRecord
    {
        return $this->originalFor($chargeReference, $provider, [
            DocumentSeries::SelfBilledInvoice,
            DocumentSeries::SettlementNote,
        ]);
    }

    /** The buyer-side receipt issued for one routed transaction, or null when none was. */
    public function buyerReceiptFor(string $chargeReference, ?string $provider = null): ?InvoiceRecord
    {
        return $this->originalFor($chargeReference, $provider, [DocumentSeries::BuyerReceipt]);
    }

    /**
     * The document a correction is about, found by the reference the sale states — AND by whose reference
     * it is.
     *
     * A charge reference is unique only per provider; the charge table says so with a composite unique key,
     * and the counter that reads it repeats the point six times. This lookup used the reference alone, so on
     * an installation with two drivers a correction could attach to another provider's document — reversing
     * a sale that was never refunded while the one that was stayed standing.
     *
     * ## Two directions, and the second one is the upgrade
     *
     * A caller that names NO provider matches on the reference alone. That is a fallback for a
     * single-driver installation, not an equivalent query, and it is written down rather than defaulted
     * silently: the two are the same answer only while there is one driver.
     *
     * A caller that DOES name one also matches a row that recorded NONE — and that is not laxity, it is the
     * only reading that survives the upgrade. Every document a marketplace issuer wrote before the provider was
     * frozen carries null, so a strict pair match would make each of them unreachable the moment this shipped: a refund
     * would silently find no settlement to correct, on every historical sale, with nothing red anywhere.
     *
     * The cost of including them is bounded in a way the exclusion's is not. A null row can only have been
     * written while the installation recorded no provider at all — which is before a second driver existed —
     * so it belongs to whichever one there was. The exclusion's cost is certain, immediate and universal;
     * this one needs a reference collision with a driver that did not exist when the row was written.
     *
     * @param  list<DocumentSeries>  $series
     */
    private function originalFor(string $chargeReference, ?string $provider, array $series): ?InvoiceRecord
    {
        return InvoiceRecord::query()
            ->where('settled_charge_reference', $chargeReference)
            ->when(
                $provider !== null && $provider !== '',
                fn (Builder $q): Builder => $q->where(
                    fn (Builder $pair): Builder => $pair->where('provider', $provider)->orWhereNull('provider'),
                ),
            )
            ->whereIn('document_series', array_map(fn (DocumentSeries $s): string => $s->value, $series))
            ->whereNull('credited_invoice_id')
            ->whereNull('credited_invoice_number')
            // The EXACT match first. Admitting NULL rows is what carries the upgrade, and it also makes a
            // legacy row a candidate for a provider it may not belong to -- with `orderBy('id')` alone the
            // older row has the smaller id and WINS against the document that names this provider outright.
            ->when(
                $provider !== null && $provider !== '',
                fn (Builder $q): Builder => $q->orderByRaw('case when provider = ? then 0 else 1 end', [$provider]),
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * Issue the BUYER-side correcting document for a refund.
     *
     * Its figures are the outbound ones — what the buyer is given back and the tax the platform no longer
     * owes on it — never the merchant-side ones. The two are different amounts about different supplies, and
     * a document carrying the wrong pair would reconcile with itself and describe the wrong party.
     */
    public function issueForBuyer(
        InvoiceRecord $original,
        ChainCorrection $correction,
        CarbonImmutable $correctedOn,
        ?TaxBaseChangeReason $reason = null,
        ?RefundAttempt $attempt = null,
    ): ?InvoiceRecord {
        if (! $correction->buyerRefund->isPositive()) {
            return null;
        }

        return $this->write(
            $original, $correctedOn,
            $correction->outboundNet->minorUnits,
            $correction->outboundTax->minorUnits,
            $correction->buyerRefund->minorUnits,
            reverseCharge: false,
            reason: $reason,
            attempt: $attempt,
        );
    }

    /**
     * Issue the creator-side correcting document for a refund.
     *
     * Returns null when the correction touches no creator-side document — a sale to a creator whose standing
     * was never established was never settled, so there is nothing to correct on their side. The buyer's side
     * is corrected regardless, and that is a different document.
     */
    public function issue(
        InvoiceRecord $original,
        ChainCorrection $correction,
        CarbonImmutable $correctedOn,
        ?TaxBaseChangeReason $reason = null,
        ?RefundAttempt $attempt = null,
    ): ?InvoiceRecord {
        if (! $correction->correctsInboundDocument()) {
            return null;
        }

        // Asked AT THE ORIGINAL'S OWN MOMENT, never at the correction date. This is the whole of what "ex
        // nunc" does and does not mean: the correction belongs in TODAY's tax period, and it states the
        // treatment the supply had WHEN IT HAPPENED. Reading today's standing instead would let a creator
        // who has since become a small business receive a document stating tax -- tax that was correctly
        // charged then, and that they would now owe merely for having it written down. The whitelist checks
        // today's status, so it is exactly the check that cannot catch it.
        $this->assertMayRestateTax($original, $correction->inboundInputTax);

        return $this->write(
            $original, $correctedOn,
            $correction->inboundExpense->minorUnits,
            $correction->inboundInputTax->minorUnits,
            $correction->merchantClawback->minorUnits,
            reverseCharge: $correction->reverseChargeTax->isPositive(),
            reason: $reason,
            attempt: $attempt,
        );
    }

    /**
     * Write a correcting document against an original, with the side's own amounts.
     *
     * Everything except the amounts is identical on both sides, and shared rather than duplicated: the date
     * rule, the paired series, the origin reference and the frozen shape of the sale are properties of what a
     * correction IS, not of which party it addresses.
     */
    private function write(
        InvoiceRecord $original,
        CarbonImmutable $correctedOn,
        int $netMinor,
        int $taxMinor,
        int $totalMinor,
        bool $reverseCharge,
        ?TaxBaseChangeReason $reason = null,
        ?RefundAttempt $attempt = null,
    ): InvoiceRecord {
        $origin = $original->number;

        if ($origin === null || $origin === '') {
            throw InvalidInvoiceCorrection::amendmentWithoutReference();
        }

        $series = $this->correctionSeriesFor($original);

        $correction = InvoiceRecord::query()->create([
            'subtotal_minor' => $netMinor,
            'tax_minor' => $taxMinor,
            'total_minor' => $totalMinor,
            'reverse_charge' => $reverseCharge,
            'owner_type' => $original->owner_type,
            'owner_id' => $original->owner_id,
            'number' => $this->numbers->allocate($series, $correctedOn->year),
            'currency' => $original->currency,
            'status' => InvoiceStatus::Open,
            // The month it happened, not the month it corrects. The original stays as issued.
            'issued_at' => $correctedOn,
            'tax_exempt' => (bool) $original->tax_exempt,
            // Carried from the document being corrected, never re-derived. A correction states the same
            // supply the original stated; re-deriving the relief here would let a creator's standing change
            // between the two and quietly restate the earlier supply's legal ground.
            'tax_exemption_reason' => $original->tax_exemption_reason,
            // An amendment, not a cancellation: it corrects a specific earlier document and says which.
            'correction_kind' => InvoiceCorrectionKind::Amendment,
            // WHY the taxable amount changed. The figures cannot say it — money given back and money that
            // will not arrive produce the same correction — and only this tells a finished matter from one a
            // later payment reopens.
            'tax_base_change_reason' => $reason,
            // WHICH reversal this document documents, when one was recorded. Stored rather than inferred:
            // several confirmations can land against one charge, each capped against what was still
            // refundable at that moment, so charge and date do not identify one of them — and the amounts
            // do not either, because `ClawbackCalculator` floors at zero and every sum is capped against
            // its own ceiling. Null on the paths that hold no attempt, which says no reversal row stands
            // behind this correction, never that the reversal moved nothing.
            'refund_attempt_id' => $attempt?->id,
            'credited_invoice_id' => $original->id,
            'credited_invoice_number' => $origin,
            // The frozen shape of the sale travels to the correction unchanged: a correction that
            // re-derived it would describe a transaction that did not happen.
            //
            // Including the TAX characteristics, which is the half that was missing. They decide what the
            // document says about itself — which archetype was sold, which rule placed the supply, which
            // band applied, at what rate, to a recipient of which standing, under which version of the
            // matrix. A correction that leaves them empty states a taxable amount without stating what it
            // is a taxable amount OF, and the reader is left inferring it from the original — which is
            // exactly the inference the freezing exists to make unnecessary.
            //
            // The rate is the tell that this was an omission rather than a decision: it was already being
            // READ here, to state the rate on the correction's line, and not written to the row beside it.
            // So the same document said one thing in its line and nothing in its column.
            // And the COMMISSION terms, for the identical reason and with the identical tell. The document
            // states an amount without stating the terms the commission on it was taken under, so a reader
            // has to go back to the original to learn them — the inference the freezing exists to remove.
            //
            // `RoutedRefundCorrector::frozenCommission()` reads exactly these three columns and treats an
            // empty set as a zero commission, documented there as the honest read for a settlement written
            // BEFORE the terms were frozen. It does not reach a correction today (`settlementFor()` narrows
            // to the settlement series), so nothing computes a wrong figure from this — which is why it is a
            // completeness gap and not a live defect. It is worth closing anyway: the next reader of these
            // columns will not know that "empty" here means "never written" rather than "taken at zero", and
            // the fallback that absorbs it was written about a different situation.
            'commission_bps' => $original->commission_bps,
            'commission_flat_minor' => $original->commission_flat_minor,
            'commission_residual' => $original->commission_residual,
            'tax_archetype' => $original->tax_archetype,
            // Carried for the same reason as the archetype beside it, and it matters MORE here than it looks:
            // the correction lands in the counted period too, and a correction that lost the reference would
            // group under a different key from the document it undoes — leaving the original's inflow
            // standing in the reportable line while the reversal reduced a line nobody reports.
            'sold_alongside_archetype' => $original->sold_alongside_archetype,
            'place_of_supply_rule' => $original->place_of_supply_rule,
            'tax_rate_category' => $original->tax_rate_category,
            'tax_rate_bps' => $original->tax_rate_bps,
            'recipient_tax_status' => $original->recipient_tax_status,
            'rate_matrix_version' => $original->rate_matrix_version,
            'supply_regime' => $original->supply_regime,
            'settlement_document_type' => $original->settlement_document_type,
            'document_series' => $series,
            // Carried with the reference, because the two are one key. A correction that kept the
            // reference and dropped the provider would be a document nobody can match back to its charge
            // on a multi-driver installation -- and it would look complete while being unattributable.
            'provider' => $original->provider,
            'settled_charge_reference' => $original->settled_charge_reference,
            'seller' => $original->getAttribute('seller'),
            'buyer' => $original->getAttribute('buyer'),
            'lines' => [[
                'description' => 'Correction',
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $netMinor,
                'net_minor' => $netMinor,
                'tax_rate' => $original->tax_rate_bps === null ? 0.0 : $original->tax_rate_bps / 100,
            ]],
        ]);

        // The rate travels with the rest of the frozen shape, for the same reason stated above it: a
        // correction that re-derived it would state a figure the original never did, and the gap between
        // them would read as a currency movement neither party made.
        $this->exchangeRates->carryTo($original, $correction);

        return $correction;
    }

    /**
     * The correction series paired to the original's own.
     *
     * Read from the original rather than chosen: which series corrects which is a property of the pair, and a
     * correction drawn from the wrong one would be numbered as a correction of something else.
     */
    private function correctionSeriesFor(InvoiceRecord $original): DocumentSeries
    {
        $series = $original->document_series;

        if (! $series instanceof DocumentSeries) {
            throw new InvalidArgumentException(
                'A correction can only be issued against a document that names its series. The record given '
                .'names none, so there is no correction series it pairs with.'
            );
        }

        return match ($series) {
            DocumentSeries::SelfBilledInvoice => DocumentSeries::SelfBilledInvoiceCorrection,
            DocumentSeries::SettlementNote => DocumentSeries::SettlementNoteCorrection,
            DocumentSeries::BuyerReceipt => DocumentSeries::BuyerReceiptCorrection,
            DocumentSeries::CommissionInvoice => DocumentSeries::CommissionInvoiceCorrection,
            default => throw new InvalidArgumentException(
                'A correction can only be issued against an ORIGINAL. The record given draws from '
                .$series->value.', which is itself a correction series — correcting a correction produces a '
                .'second document about the first, which is a different act with a different reference.'
            ),
        };
    }

    /**
     * Whether the creator's standing AT THE SUPPLY permitted the tax this correction restates.
     *
     * A correcting document is not a new claim; it is a restatement of one already made. But it is still a
     * document that states tax, and the party it names still carries what it says. So it passes the same
     * whitelist the original passed — at the same moment the original passed it.
     *
     * A correction that restates no tax needs no permission and short-circuits before the owner is even
     * resolved: an unresolvable party is only fatal where something is actually being stated.
     */
    private function assertMayRestateTax(InvoiceRecord $original, Money $tax): void
    {
        if ($tax->isZero()) {
            return;
        }

        $creator = $this->ownerOf($original);

        // Fail closed. An original whose party cannot be resolved cannot have its standing checked, and an
        // unprovable permission is not a permission -- the alternative is to state tax on behalf of somebody
        // nobody can name.
        if (! $creator instanceof Model) {
            throw InvalidInvoiceCorrection::partyUnresolvable($original->number ?? (string) $original->id);
        }

        $this->disclosure->assertMayDiscloseTax(
            $creator,
            CarbonImmutable::parse($original->issued_at ?? $original->created_at),
            $tax,
        );
    }

    /**
     * The party a document names, or null where the stored type does not resolve to a model at all.
     *
     * The type column holds whatever wrote the row, and a morph type that names no class — a stale alias, a
     * value from before a morph map was introduced — makes Eloquent throw from inside the relation with a
     * message about a missing class. That is a true statement about a broken row, delivered as a framework
     * fault to whoever merely asked for a correction. Checked here so the refusal is this package's own and
     * says what to do about it.
     */
    private function ownerOf(InvoiceRecord $original): ?Model
    {
        // Read as a RAW attribute, and type-checked rather than trusted. The property is declared `string`,
        // which is what the column is meant to hold — and a row written before that was true, or by a path
        // that never filled it, holds null anyway. Taking the declaration at its word turned an empty owner
        // into a TypeError from `class_exists()` instead of this class's own refusal, which is the failure
        // this branch exists to prevent.
        $type = $original->getAttribute('owner_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class)) {
            return null;
        }

        return $original->owner;
    }
}
