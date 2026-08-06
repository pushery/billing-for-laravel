<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Contracts\SuppliesExchangeRateBasis;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\ProductNotClassified;
use Pushery\Billing\Exceptions\SelfBillingDisabled;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Pushery\Billing\Tax\FreezeExchangeRateOnDocument;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * Settles one creator's supply into the platform: it resolves what document to issue, guards it, and draws
 * its number — the orchestration the whole credit-note chain hangs from.
 *
 * The engine does NOT re-decide the tax. That is the input-side matrix's job ({@see InboundTaxMatrix}); the
 * engine consumes its treatment and turns it into a numbered, guarded plan. What it adds is the ORDER and
 * the PRECONDITIONS the matrix cannot express on its own:
 *
 *  - the creator's standing is read at the SUPPLY date, so a later status change does not rewrite a past
 *    settlement;
 *  - an unclarified creator is a hold — no document, nothing paid — carried straight through from the matrix;
 *  - a self-billed invoice requires the prior agreement (or it is not an invoice) and, if it states tax, the
 *    disclosure whitelist (or the creator would owe the stated tax);
 *  - a settlement note to a private party needs neither — it is not a self-billed invoice and states no tax;
 *  - the number is drawn from the matching series only once the guards have passed, so a refused settlement
 *    never consumes one.
 *
 * The names are the code's own — self-billing is the concept, "Gutschrift" only its German document. The
 * persistence and rendering of the planned document, and the fallback lane for a creator who is not
 * self-billed, are separate steps; this is the decision and the numbering.
 */
final readonly class SelfBillingEngine
{
    public function __construct(
        private CreatorTaxStatusResolver $status,
        private InboundTaxMatrix $matrix,
        private DocumentNumberAllocator $numbers,
        private SelfBillingAgreementGuard $agreementGuard,
        private CreatorTaxDisclosureGuard $disclosureGuard,
        private MerchantPartyResolver $merchantParty,
        private Repository $config,
        private DocumentDeliveryLog $deliveries = new DocumentDeliveryLog,
        /**
         * Freezes the document's conversion rate, on the sales that have one.
         *
         * Optional and defaulted, because the overwhelming majority of installations are single-currency
         * and never convert: requiring it would make every consumer construct a seam they will never
         * reach. A sale in the reporting currency does not touch this at all.
         */
        private ?FreezeExchangeRateOnDocument $exchangeRates = null,
        private ?CheckpointRegistry $profiles = null,
    ) {}

    /**
     * The rule this jurisdiction issues its documents under, or null if it does not say.
     *
     * Null is a real answer and not a gap: the three conversion rules contradict each other on the same
     * turnover, so an installation that has declared no profile has declared no rule, and freezing one
     * anyway would pick a side on its behalf.
     */
    private function documentRateBasis(): ?ExchangeRateBasis
    {
        $profile = $this->profiles?->profile();

        return $profile instanceof SuppliesExchangeRateBasis ? $profile->documentExchangeRateBasis() : null;
    }

    /** Whether this sale converts at all — a sale in the reporting currency does not. */
    private function converts(Money $amount): bool
    {
        $reporting = $this->config->get('billing.currency');

        return is_string($reporting) && strtoupper($amount->currency) !== strtoupper($reporting);
    }

    /**
     * Refuse a foreign-currency settlement whose rate nobody holds — before a number is drawn.
     *
     * Three conditions have to line up before this can refuse anything, and each is a deliberate limit on
     * its reach. The sale has to actually convert; a jurisdiction profile has to have said which rule
     * applies; and the seam has to be wired at all. A single-currency install, an install with no profile,
     * and an install that never bound the rate store each pass straight through — which is every install
     * that has not opted into holding rates.
     *
     * Where it does apply, the refusal is the point. A settlement document in a foreign currency states an
     * amount in the reporting currency somewhere downstream, and producing one with no published rate means
     * producing a figure nobody issued.
     */
    private function assertExchangeRateObtainable(Money $transactionNet, CarbonImmutable $supplyDate): void
    {
        $basis = $this->documentRateBasis();
        $reporting = $this->config->get('billing.currency');

        if (! $this->exchangeRates instanceof FreezeExchangeRateOnDocument
            || ! $basis instanceof ExchangeRateBasis
            || ! is_string($reporting)
            || ! $this->converts($transactionNet)) {
            return;
        }

        // Asked in the PUBLISHED direction: the bank states how many units of the sale's currency one unit
        // of the reporting currency buys, and a rate is never turned around to answer the other way.
        $this->exchangeRates->assertObtainable(
            strtoupper($reporting),
            strtoupper($transactionNet->currency),
            $supplyDate,
            $basis,
        );
    }

    /** Freeze the document's own conversion, once the document exists to carry it. */
    private function freezeExchangeRate(InvoiceRecord $record, Money $transactionNet, CarbonImmutable $supplyDate): void
    {
        $basis = $this->documentRateBasis();
        $reporting = $this->config->get('billing.currency');

        if (! $this->exchangeRates instanceof FreezeExchangeRateOnDocument
            || ! $basis instanceof ExchangeRateBasis
            || ! is_string($reporting)
            || ! $this->converts($transactionNet)) {
            return;
        }

        $this->exchangeRates->freeze(
            $record,
            ExchangeRateLayer::Document,
            strtoupper($reporting),
            strtoupper($transactionNet->currency),
            $supplyDate,
            $basis,
        );
    }

    /**
     * @param  CarbonImmutable  $supplyDate  the TAX POINT of the outbound side — never "today", and never
     *                                       the moment this credit happens to be issued.
     *
     * The two chain links are one transaction seen twice, so they belong to the same period. Where the
     * outbound side is taxed on receipt (a prepaid year, § 13 Abs. 1 Nr. 1 Buchst. a S. 4 UStG), that is the
     * month the money arrived, and this date must be that month — not the eleven that follow. Passing "now"
     * instead splits the pair: outbound tax in January, input tax spread across the year, and an offset
     * nobody notices until an accountant cannot make two periods agree.
     *
     * It is not a presentational choice. This date resolves the creator's status, both guards, AND the YEAR
     * of the allocated document number — and a document number's year is frozen the moment it is drawn.
     */
    public function settle(
        Model $creator,
        SupplyRegime $regime,
        Money $transactionNet,
        PlatformFee $commission,
        int $supplyRateBps,
        CarbonImmutable $supplyDate,
        ?TaxArchetype $archetype = null,
    ): SettlementOutcome {
        $plan = $this->plan($creator, $regime, $transactionNet, $commission, $supplyRateBps, $supplyDate);

        if ($plan->isHold || ! $plan->series instanceof DocumentSeries) {
            return $plan;
        }

        // HERE, and not in plan(), and the difference is the whole rule. Under a REVERSE CHARGE the archetype
        // decides which category the document carries -- `K` with VATEX-EU-IC for an intra-Community supply of
        // goods, `AE` for a service -- and those name different provisions and different obligations for the
        // recipient. Left null, `EnInvoiceTaxCategory` reads "not goods" and prints AE: right for a digital
        // supply, a wrong statement of the provision for every other, arrived at without ever being asked.
        //
        // plan() is also what the monthly COLLECTIVE run calls, once per transaction, and a collective
        // document deliberately carries no archetype -- it covers many supplies and a creator who sold a
        // download and a commissioned work in the same month has no single one. There the absence is the
        // answer. Refusing in plan() would refuse every reverse-charged collective settlement, on every
        // install, for a value they are right not to carry.
        //
        // settle() is the single-supply path by construction: it is plan plus ONE number, which is precisely
        // what the collective run must not do. So one document, one supply, one archetype -- and the refusal
        // still lands before allocate(), because a burnt number in a gapless series is not recoverable.
        //
        // Only under a reverse charge. Demanding it for a plain domestic settlement would refuse documents to
        // buy nothing: there the archetype changes nothing a reader of one can see.
        if ($plan->treatment?->reverseChargeToRecipient === true && ! $archetype instanceof TaxArchetype) {
            throw ProductNotClassified::beforeReverseChargedDocument('a self-billed settlement');
        }

        // The number is drawn only now that the plan's guards have passed, so a refused settlement never
        // consumes one — and from the SUPPLY year, so a document dated into December keeps that year's series.
        return SettlementOutcome::document(
            $plan->series,
            $this->numbers->allocate($plan->series, $supplyDate->year),
            $plan->treatment ?? throw new LogicException('A non-hold plan always carries a treatment.'),
        );
    }

    /**
     * Everything settle() decides EXCEPT drawing a number: the treatment, the document series, and the guards.
     *
     * The monthly collective run ({@see CollectiveSelfBillingEngine}) plans every transaction of a creator's
     * month this way and draws a SINGLE number for the whole document — so the plan must run the guards (each
     * line's tax must clear the disclosure whitelist, each supply date must be covered by the agreement) but
     * must not consume a number per line. settle() is this plus one number.
     */
    public function plan(
        Model $creator,
        SupplyRegime $regime,
        Money $transactionNet,
        PlatformFee $commission,
        int $supplyRateBps,
        CarbonImmutable $supplyDate,
    ): SettlementOutcome {
        // Backstop, not the routing decision: a platform that does not self-bill checks the switch and stays
        // in the fallback lane before reaching here. Reaching here with it off is a caller mistake.
        if ($this->config->get('billing.marketplace.self_billing.enabled', true) !== true) {
            throw SelfBillingDisabled::make();
        }

        $status = $this->status->statusAt($creator, $supplyDate);
        $treatment = $this->matrix->resolve($regime, $status, $transactionNet, $commission, $supplyRateBps);

        if ($treatment->isHold()) {
            return SettlementOutcome::hold();
        }

        // THE RATE CHECK BELONGS TO THE CURRENCY, NOT TO THE DOCUMENT TYPE, and it used to sit inside the
        // self-billed branch below. A settlement note in SEK converts exactly as a self-billed invoice in SEK
        // does -- `converts()` asks only `billing.currency` against the amount's -- so the note reached
        // `issue()`, drew a number, wrote the row, and only then failed on the freeze.
        //
        // Which is the precise state this guard exists to prevent. The comment above `freezeExchangeRate()`
        // says as much -- "failing after the number is exactly the case the guard exists to prevent" -- and
        // for a settlement note it was describing what happened rather than what was avoided. A burnt number
        // in a series that has to be gapless is not recoverable by retrying.
        //
        // It runs BEFORE the branch, so no future document type can be added past it by accident.
        $this->assertExchangeRateObtainable($transactionNet, $supplyDate);

        if ($treatment->document === SettlementDocumentType::SelfBilledInvoice) {
            // The two hard preconditions of a self-billed invoice, checked before a number is drawn: the prior
            // agreement (without which the document is not an invoice) and the disclosure whitelist (without
            // which stated tax would fall on the creator). A zero-tax treatment passes the disclosure guard.
            $this->agreementGuard->assertMayIssueSelfBilledInvoice($creator, $supplyDate);
            $this->disclosureGuard->assertMayDiscloseTax($creator, $supplyDate, $treatment->taxAmount);

            return SettlementOutcome::planned(DocumentSeries::SelfBilledInvoice, $treatment);
        }

        // A plain settlement note to a party who issues no invoice (a private individual): no agreement is
        // required and it states no tax, so neither of THOSE two guards applies. The rate check above does.
        return SettlementOutcome::planned(DocumentSeries::SettlementNote, $treatment);
    }

    /**
     * Settle the supply AND persist the resulting document — the parties reversed, the platform the buyer of
     * the creator's supply, the creator the seller.
     *
     * A hold persists nothing and returns null. Otherwise the document carries the treatment's amounts (the
     * creator's supply net, its tax, and the payout that is their sum), the drawn number, and the seller
     * frozen from the merchant's own party. The seller is snapshotted here, at issue, so the document keeps
     * naming who it named even after the merchant's details change; the buyer is the platform.
     */
    public function issue(
        Model $creator,
        SupplyRegime $regime,
        Money $transactionNet,
        PlatformFee $commission,
        int $supplyRateBps,
        CarbonImmutable $supplyDate,
        ?string $settledChargeReference = null,
        ?TaxArchetype $archetype = null,
        ?PlaceOfSupplyRule $placeOfSupply = null,
        ?TaxRateCategory $rateCategory = null,
        ?TaxArchetype $soldAlongside = null,
        ?string $provider = null,
    ): ?InvoiceRecord {
        $outcome = $this->settle($creator, $regime, $transactionNet, $commission, $supplyRateBps, $supplyDate, $archetype);
        $treatment = $outcome->treatment;

        if (! $treatment instanceof InboundTaxTreatment) {
            return null;
        }

        $net = $treatment->payoutAmount->minus($treatment->taxAmount);

        // The fan-side gross of this transaction, frozen so the DATEV chain can post its fan leg (money-in
        // against revenue). It is the fan net plus the output VAT the fan paid — commercially rounded per
        // transaction, the same basis every downstream aggregate uses.
        $fanTaxMinor = (int) round($transactionNet->minorUnits * $supplyRateBps / 10_000);
        $fanGrossMinor = $transactionNet->minorUnits + $fanTaxMinor;

        $record = InvoiceRecord::query()->create([
            'owner_type' => $creator->getMorphClass(),
            'owner_id' => $creator->getKey(),
            'number' => $outcome->number,
            'currency' => $transactionNet->currency,
            'status' => InvoiceStatus::Open,
            'issued_at' => $supplyDate,
            'subtotal_minor' => $net->minorUnits,
            'tax_minor' => $treatment->taxAmount->minorUnits,
            'total_minor' => $treatment->payoutAmount->minorUnits,
            'reverse_charge' => $treatment->reverseChargeToRecipient,
            'tax_exempt' => $treatment->exempt,
            // The characteristics of the CREATOR's supply, frozen the same way the amounts are. This
            // document is the one where goods-versus-services actually changes what is rendered: a
            // reverse-charged supply takes `AE` as a service and `K` with VATEX-EU-IC as goods, and the
            // renderer decides that from `tax_archetype` alone. With nothing writing the column every
            // settlement rendered `AE`, which is the right answer for a digital supply and a wrong statement
            // of the provision for any other — arrived at without the document ever being asked.
            'tax_archetype' => $archetype,
            // What a voluntary payment was paid ON. Frozen beside the archetype rather than left to the
            // request, because it is the input the reporting run needs and the run happens months later,
            // over documents. Without it a tip settles as `tip` and nothing else, and the rule that decides
            // whether this creator has to be reported answers from the tip alone — which is the one question
            // the taxonomy expressly declines to answer without this reference.
            'sold_alongside_archetype' => $soldAlongside,
            'place_of_supply_rule' => $placeOfSupply,
            'tax_rate_category' => $rateCategory,
            // BT-72. Not a new argument here: a settlement documents ONE supply and this method is already
            // told when it happened -- the same date it dates the document to. Asking for it twice would be
            // two places for one fact, and the second one would eventually disagree.
            'delivered_on' => $supplyDate,
            // The regime and the document role are frozen so the role guard on the record can prove this
            // settlement belongs to the commission chain — a self-billed invoice or settlement note here,
            // never a commission invoice. The posture is not frozen on a self-billed document: it names the
            // creator as seller, which the seller-vs-posture guard reads as "not the platform", so the regime
            // (the posture's locked twin) is the field this document carries.
            'supply_regime' => $regime,
            'settlement_document_type' => $treatment->document,
            'document_series' => $outcome->series,
            'fan_gross_minor' => $fanGrossMinor,
            // Which routed transaction this settles. Without it the document is unreachable from the sale:
            // a refund knows the charge, and the correction it owes would have to find this document by
            // matching amounts and dates — a guess — or not at all.
            // WHOSE reference that is. The charge table is unique on the pair, so a settlement that stored
            // only the reference cannot be matched back to its charge once a second driver exists -- and the
            // DAC7 fee figure that has to be derived from exactly that join would then read more than one row.
            'provider' => $provider,
            'settled_charge_reference' => $settledChargeReference,
            // The terms this sale was priced under. A correction recomputes the merchant's share on what
            // REMAINS, and reading the rate from configuration then would use whatever it says that day —
            // quietly shrinking every historical clawback after a rate cut, and over-collecting after a
            // rise, with the document still adding up. Both parts, because a fixed component is charged
            // once and is not halved by a half refund.
            'commission_bps' => $commission->bps,
            'commission_flat_minor' => $commission->flatMinor,
            // …including WHICH SIDE kept the odd minor unit. Without it a correction has to assume a
            // direction, and on an installation that hands the cent the other way it comes back one cent off
            // the sale it corrects — on every uneven split, silently.
            'commission_residual' => $commission->residual,
            'seller' => $this->merchantParty->partyFor($creator)->toArray(),
            'buyer' => $this->platformParty(),
            'lines' => [[
                'description' => 'Platform settlement',
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $net->minorUnits,
                'net_minor' => $net->minorUnits,
                'tax_rate' => $treatment->showsTax ? $supplyRateBps / 100 : 0.0,
            ]],
        ]);

        // The rate this document was converted at, frozen onto it now that there is a document to carry it.
        // Its availability was already proved in plan(), before the number was drawn, for EVERY document
        // type — the check sits ahead of the branch there precisely so this sentence is true. It lived
        // INSIDE the self-billed branch once, and then this claim was false for a settlement note: the
        // number was drawn, the row was written, and the freeze failed. Which is exactly the case the
        // guard exists to prevent, and a burnt number in a gapless series is not fixed by retrying.
        $this->freezeExchangeRate($record, $transactionNet, $supplyDate);

        // The document now exists where its recipient can reach it, which is one of the two things that
        // together deliver it. The other — telling them — belongs to whatever channel the consuming
        // application uses, so it records that itself; what the package can honestly attest is this half,
        // and attesting it at the moment of issue is the only time the claim is true by construction.
        //
        // Recording only half is deliberate rather than incomplete: `delivered()` stays false until the
        // notification lands, so a document that was issued and never announced reads as what it is instead
        // of as delivered.
        $this->deliveries->provided($record->number ?? (string) $record->id, $creator);

        return $record;
    }

    /** @return array<string, ?string> */
    private function platformParty(): array
    {
        $company = $this->config->get('billing.company');

        return Party::fromArray(is_array($company) ? $company : [])->toArray();
    }
}
