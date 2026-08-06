<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\FanReceiptTier;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ServicePeriod;

/**
 * The buyer's document for a routed sale, at the least tier the purchase needs.
 *
 * The tier decision is made elsewhere ({@see FanReceiptTierResolver}); this issues what it decided. What it
 * adds is the consequence that decision has for DATA: the two lower tiers carry no buyer identity at all,
 * because they do not need one — and a document that carried it anyway would be collection without a ground.
 *
 * In a commission chain that matters twice over. The buyer's identity is not merely surplus there: the same
 * document that names them also names the platform as seller, and pairing the two is how a buyer and a
 * merchant learn each other's identity from a receipt neither asked for.
 *
 * A buyer who ASKS for a full invoice is the one case where the data is collected, because then it is
 * required — and the asking is what makes it lawful to hold. A caller who supplies buyer details for a lower
 * tier is refused rather than quietly trimmed: passing them means the call site believes they belong on the
 * document, and silently dropping them would leave that belief in place and untested.
 *
 * Nothing here is jurisdictional. Which purchase falls into which tier, and at what threshold, is the
 * resolver's answer — {@see FanReceiptTierResolver}, which reads the threshold from the plain config key
 * `billing.marketplace.receipts.small_amount_threshold_minor` and consults no profile. This turns a tier
 * into a row.
 */
final readonly class FanReceiptIssuer
{
    public function __construct(
        private DocumentNumberAllocator $numbers,
        private Repository $config,
    ) {}

    /**
     * Issue the buyer's document for a routed sale.
     *
     * @param  Money  $gross  what the buyer paid
     * @param  ?array<string, mixed>  $buyer  the buyer's details — permitted ONLY for a full invoice
     * @param  ?ServicePeriod  $period  the stretch this document covers, where it covers one. A subscription
     *                                  cycle is a PART-SUPPLY and its document says which part; a one-off
     *                                  purchase has no period and states none.
     * @param  ?TaxArchetype  $archetype  what was sold, in the terms the tax treatment turns on
     * @param  ?PlaceOfSupplyRule  $placeOfSupply  where the supply was taxed
     * @param  ?TaxRateCategory  $rateCategory  which rate band it fell in
     * @param  ?CarbonImmutable  $deliveredOn  when the supply was actually made — EN 16931 BT-72
     * @param  ?TaxExemptionReason  $exemptionReason  why no tax was charged, where none was
     *
     * The delivery date is supplied and never derived, which is what both e-invoice writers already say in
     * their own comments: a subscription billed in advance issues on the first of the month, and a value
     * taken from the period's end would state a delivery in the future on a document dated before it. A
     * document that covers a STRETCH says so with its service period instead, and leaves this null — the two
     * are different claims and the standard has a term for each.
     *
     * The last three are the sale's tax characteristics, and they are frozen here for the same reason the
     * rate and the tier already are: the document has to keep saying what the supply WAS, after the product
     * behind it has legitimately changed. They arrive from the caller rather than being looked up, because
     * nothing in this class is jurisdictional and the catalog is the consuming application's.
     *
     * Three parameters rather than one object, deliberately. They are independently known — a caller may
     * have the archetype from a catalog and the band from a rate matrix — and a wrapper would have to invent
     * a rule for a partially-filled one. All three default to null, so every existing call site writes the
     * same row it wrote before: the columns have been nullable since they were added, and until this
     * assignment existed nothing filled any of them on a document this package issued.
     */
    public function issue(
        Model $buyerOwner,
        FanReceiptTier $tier,
        Money $gross,
        int $taxRateBps,
        CarbonImmutable $soldOn,
        ?string $chargeReference = null,
        ?array $buyer = null,
        ?ServicePeriod $period = null,
        ?TaxArchetype $archetype = null,
        ?PlaceOfSupplyRule $placeOfSupply = null,
        ?TaxRateCategory $rateCategory = null,
        ?CarbonImmutable $deliveredOn = null,
        ?TaxExemptionReason $exemptionReason = null,
        ?TaxArchetype $soldAlongside = null,
        ?string $provider = null,
    ): InvoiceRecord {
        if ($buyer !== null && $tier !== FanReceiptTier::FullInvoice) {
            throw new InvalidArgumentException(
                'Buyer details belong only on a full invoice, which is issued when the buyer asks for one. '
                .'A simplified receipt or a payment record carries none — holding them there would be '
                .'collection with no ground, and in a commission chain it would also disclose the buyer and '
                .'the merchant to each other. Drop them at the call site rather than here, so the decision '
                .'stays where somebody made it.'
            );
        }

        // Tax as the DIFFERENCE from the gross, the same way the sale was priced: computing the net from the
        // rate and the tax from the net loses a cent the buyer nonetheless paid.
        [$net, $tax] = $gross->baseFromMarkup($taxRateBps);

        // Everything below is inside the closure for one reason: `issueOnce()` may not run it at all. A cycle
        // that already has a document returns that document, and a number drawn on the way to finding that
        // out would be a number no document carries.
        return $this->issueOnce($buyerOwner, $period, fn (): InvoiceRecord => InvoiceRecord::query()->create([
            'owner_type' => $buyerOwner->getMorphClass(),
            'owner_id' => $buyerOwner->getKey(),
            'number' => $this->numbers->allocate(DocumentSeries::BuyerReceipt, $soldOn->year),
            'currency' => $gross->currency,
            'status' => InvoiceStatus::Open,
            'issued_at' => $soldOn,
            'subtotal_minor' => $net->minorUnits,
            'tax_minor' => $tax->minorUnits,
            'total_minor' => $gross->minorUnits,
            'tax_rate_bps' => $taxRateBps,
            'fan_gross_minor' => $gross->minorUnits,
            'supply_regime' => SupplyRegime::CommissionChain,
            // The regime's locked twin, written out rather than left implied — and the reason is a guard,
            // not tidiness. Three checks on the model read this column and all three return early without
            // it, so none of them has ever fired on a document this package issued.
            //
            // Two of the three become tautological once it is derived here (the posture comes FROM the
            // regime, so it cannot contradict it), and that is the trade. The third does not: it compares
            // the posture against the party actually snapshotted as seller, and catches a document that
            // claims the platform is deemed supplier while naming somebody else. That one is worth two
            // tautologies — and it stays sharp for rows a consumer writes, which this model is public
            // surface for.
            'seller_posture' => SupplyRegime::CommissionChain->requiredPosture(),
            'document_series' => DocumentSeries::BuyerReceipt,
            // The tier this document IS, frozen when it is issued. It was decided from this document's own
            // gross, and re-deriving it later — after a threshold moved, say — would render the document as
            // something it never was.
            'receipt_tier' => $tier,
            // The tax characteristics of the supply, frozen beside the tier and for the same reason. The
            // renderers and the periodic return read these columns and had no writer at all: every document
            // this package issued carried null, and both readers treat null as an answer rather than as an
            // absence — the EN 16931 category reads it as "a service", and the return files the sale under
            // the standard band whatever rate it actually carried.
            'tax_archetype' => $archetype,
            // What a voluntary payment was paid ON. The two columns beside it are the DERIVED answers; this
            // is what they were derived FROM, and keeping it is what lets a reader check the derivation
            // rather than take it. It is also the transaction line's reference to the thing it accompanied,
            // which a tip otherwise has no way of naming.
            'sold_alongside_archetype' => $soldAlongside,
            'place_of_supply_rule' => $placeOfSupply,
            'tax_rate_category' => $rateCategory,
            // BT-72, and it had the same shape as the characteristics above: both renderers emit it, the
            // column is frozen against later change, and no issuer wrote it — so the term was absent on
            // every document this package produced while a closed ticket said it rendered.
            'delivered_on' => $deliveredOn,
            // WHY no tax was charged, where none was. It has to be stated rather than inferred, because the
            // renderer can only infer ONE of the two: with this absent it falls back to "reverse charge, or
            // nothing", so `SuppliedOutsideTheUnion` was unreachable — and with it EN 16931 category `G`.
            // An export to a third country could not be stated as one on any document this package issued,
            // while the renderer has been able to render it and a test proves so against a hand-built row.
            //
            // The two are not interchangeable and the difference is not cosmetic: a reverse-charged supply
            // IS taxed, by the other party, and belongs in a return on both sides. An export placed outside
            // the union is taxed by nobody. Rendering the first where the second happened tells the recipient
            // to account for tax that nothing is owed on.
            'tax_exemption_reason' => $exemptionReason,
            // WHICH provider's reference that is. Frozen beside it because the two are one key: the charge
            // table is unique on the pair, and a reference on its own is a prefix rather than an identifier.
            // A document that stored only the reference could be matched to another provider's sale — and
            // the failure direction is a receipt that is never issued at all.
            'provider' => $provider,
            'settled_charge_reference' => $chargeReference,
            // The dedup key of a billing cycle, and null for anything that is not one. A one-off purchase
            // has no period to repeat, so it carries none and reads exactly as it always did.
            'settlement_period' => $period?->key(),
            // The same stretch at the DOCUMENT level, which is a separate statement from the line's and is
            // what EN 16931 asks for as BG-14. Both e-invoice writers render these columns; until this
            // assignment existed nothing filled them, so the term they emit was empty on every document the
            // package issued — the renderers were tested with rows built by hand, and a test that supplies
            // its own input cannot notice that nothing else does.
            'service_period_start' => $period?->startsOn(),
            'service_period_end' => $period?->endsOn(),
            // The platform sells to the buyer in this regime, so it is the seller named here — the mirror of
            // the settlement, where the merchant is.
            'seller' => $this->platformParty(),
            // Empty rather than null for a lower tier: the document HAS a buyer side, it just carries no
            // identity. Null would read as "not yet filled in", which invites somebody to fill it in.
            'buyer' => $buyer ?? [],
            // The line states the stretch it covers when there is one. Additive and absent otherwise, so a
            // document without a period renders byte-for-byte what it always did — the EN 16931 period terms
            // appear only when a period was actually supplied over time.
            'lines' => [array_filter([
                'description' => $period instanceof ServicePeriod ? 'Subscription period' : 'Purchase',
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $net->minorUnits,
                'net_minor' => $net->minorUnits,
                'tax_rate' => $taxRateBps / 100,
                'period_start' => $period?->startsOn(),
                'period_end' => $period?->endsOn(),
            ], static fn (mixed $value): bool => $value !== null)],
        ]), $chargeReference, $provider);
    }

    /**
     * The cycle's document, returning the one already issued rather than drawing a second number for it.
     *
     * ## Two different repeats, and only one of them is common
     *
     * A payment event that arrives twice, one after the other, is the normal thing these paths exist for —
     * a provider redelivers, a billing run is retried. That repeat is recognized by READING, before anything
     * is written and before a number is drawn, and it is the reason this method is asked before `issue()`
     * builds a row.
     *
     * Two deliveries arriving at the SAME time are a different case: both read, both find nothing, both
     * write, and the unique index on (owner, series, period) refuses the second. That refusal is what makes
     * "one cycle, one document" true rather than hoped for, and it is left to surface. The provider records
     * a failed delivery and sends the event again, and the resend takes the read path above and gets the
     * document. So the outcome of the race is still exactly one document, arrived at one delivery later.
     *
     * ## Why the loser is not quietly handed the winner's document instead
     *
     * That was built, and it cannot be done portably. Absorbing the violation means reading the winner back
     * after a failed insert, and the two engines this package is proven on demand OPPOSITE things — neither
     * of them visible on SQLite:
     *
     * - **Postgres** refuses every further statement in a transaction once one has failed ("current
     *   transaction is aborted"), so the recovery read is itself refused unless a rollback comes first.
     * - **MySQL** does not need that rollback and would not reliably permit it: measured on 8.4, the
     *   rollback `DB::transaction()` performs comes back "SAVEPOINT trans2 does not exist", and that
     *   PDOException then REPLACES the violation being absorbed. It only appears when the call is nested
     *   inside another transaction — the shape a careful consumer produces, and the shape every test has.
     *
     * A mechanism whose failure mode is "works alone, breaks inside your transaction" is worse than the
     * plain refusal it was meant to soften, because it fails where somebody took care. The read above
     * removes the common repeat; the rare one is a retry, which providers already do.
     *
     * A PERIODLESS document repeats on its charge reference instead, and that is not a nicety. This used to
     * read "a document with no period is not a cycle and cannot repeat, so it goes straight to the write" —
     * true only while the sole caller was the subscription cycle. A routed one-time sale has no period and
     * IS redelivered: providers retry, and a webhook arriving twice would draw a second number from a
     * gapless series. A gap there cannot be healed by repeating the operation.
     *
     * Matched on `settled_charge_reference` because that is the value the sale itself states — the same
     * anchor the settlement side already uses, rather than a second notion of "the same sale".
     *
     * ## And on the PROVIDER beside it, because the reference alone is not a key
     *
     * `billing_merchant_charges` is unique on `(provider, charge_reference)`, and the counter that reads it
     * says so six times in its own words: never the reference alone, which is unique only per provider.
     * This lookup did use the reference alone — so on an installation with two drivers, a sale whose
     * reference happened to collide with another provider's would find THAT sale's document and issue
     * none of its own. The buyer gets no receipt and nothing turns red: a repeat and a collision are
     * indistinguishable to a query that cannot see which provider either belongs to.
     *
     * Latent today, because exactly one driver ships. It arms itself the day a second one does.
     *
     * A caller that names no provider is matched on the reference alone, as before. That is a REAL
     * fallback and not an equivalent one: it is sound on a single-driver installation and nowhere else,
     * which is why it is said here rather than left as a default that looks total.
     *
     * A caller that DOES name one also matches a document that recorded NONE, and that direction is the
     * upgrade. Every receipt a marketplace issuer wrote before the provider was frozen carries null, so a strict pair match
     * would stop recognizing them the moment this shipped — and the failure would be a SECOND document
     * drawn for a sale that already had one, from a series that must have no gaps. A null row can only have
     * been written while the installation recorded no provider at all, which is before a second driver
     * existed, so it belongs to whichever one there was.
     *
     * With neither a period nor a reference there is nothing to recognize a repeat by, and the write stands.
     *
     * @param  Closure(): InvoiceRecord  $issue
     */
    private function issueOnce(
        Model $buyerOwner,
        ?ServicePeriod $period,
        Closure $issue,
        ?string $chargeReference = null,
        ?string $provider = null,
    ): InvoiceRecord {
        if (! $period instanceof ServicePeriod) {
            if ($chargeReference === null || $chargeReference === '') {
                return $issue();
            }

            $existing = $this->buyerDocuments($buyerOwner)
                ->where('settled_charge_reference', $chargeReference)
                ->when(
                    $provider !== null && $provider !== '',
                    fn (Builder $q): Builder => $q->where(
                        fn (Builder $pair): Builder => $pair->where('provider', $provider)->orWhereNull('provider'),
                    ),
                )
                ->when(
                    $provider !== null && $provider !== '',
                    // The EXACT match first. Admitting NULL rows is what carries the upgrade, and it also
                    // makes a legacy row a candidate for a provider it may not belong to -- with `orderBy('id')`
                    // alone the older row has the smaller id and WINS against the document that names this
                    // provider outright. That is the collision this scoping was written to prevent, arriving
                    // through the fallback that was meant to be harmless.
                    fn (Builder $q): Builder => $q->orderByRaw('case when provider = ? then 0 else 1 end', [$provider]),
                )
                // A deterministic order at all, which this lacked entirely: `first()` over an unordered
                // result decides whether a number is drawn from a gapless series, and the database is under
                // no obligation to answer the same way twice.
                ->orderBy('id')
                ->first();

            return $existing instanceof InvoiceRecord ? $existing : $issue();
        }

        $existing = $this->documentFor($buyerOwner, $period)
            ?? $this->documentCovering($buyerOwner, $period);

        return $existing instanceof InvoiceRecord ? $existing : $issue();
    }

    /** This exact cycle's document, matched on the key the cycle itself states. */
    private function documentFor(Model $buyerOwner, ServicePeriod $period): ?InvoiceRecord
    {
        return $this->buyerDocuments($buyerOwner)
            ->where('settlement_period', $period->key())
            ->first();
    }

    /**
     * A document already issued for a LONGER stretch that swallows this cycle whole.
     *
     * ## The case, and it doubles the tax without breaking anything
     *
     * A term paid up front is one document for the whole year, dated in the month the money arrived. If a
     * scheduler then walks that subscription's cycles — which is what a scheduler does — every month asks for
     * a document of its own, and the key lookup above says no such document exists. It is right: the prepaid
     * document's key is the whole term, the cycle's key is one month, and the unique index cannot see that
     * one contains the other either.
     *
     * Measured on this package before this method existed: a prepaid year plus its twelve cycles produced
     * THIRTEEN documents and 37.96 of tax where 19.00 was owed. Nothing about any single one of them looks
     * wrong — each states a real period and a correct amount. The error is only in the sum, which no document
     * carries and no reader is looking at.
     *
     * ## Why this cannot collide with the identity lookup above
     *
     * It looks like it should need a guard against matching the cycle's own document — the bounds are equal
     * there, so a containment test finds it. It does not need one, and a clause requiring the cover to be
     * strictly wider was built and REMOVED: neutralizing it failed no test, because it could not change an
     * answer. `ServicePeriod::key()` is derived from the two dates, so "same bounds" and "same key" are one
     * statement, and the key lookup has already returned that document before this runs.
     *
     * A guard that cannot change an outcome reads in the test output exactly like a guard that works, which
     * is the reason it is gone rather than kept for comfort.
     *
     * A cycle that falls AFTER the prepaid term is not covered and is issued normally, so a subscription that
     * was prepaid for a year and then continues monthly keeps billing.
     */
    private function documentCovering(Model $buyerOwner, ServicePeriod $period): ?InvoiceRecord
    {
        return $this->buyerDocuments($buyerOwner)
            ->whereNotNull('service_period_start')
            ->whereNotNull('service_period_end')
            // `whereDate`, not a plain comparison against the date string, and the difference is a whole
            // month. The columns are `date` casts, but a date is SERIALIZED with the connection's datetime
            // format, so the stored value reads "2026-01-01 00:00:00". Compared against the bare
            // "2026-01-01" a string comparison finds it GREATER — the longer string wins on the shared
            // prefix — so the one cycle whose start equals the term's start slips through while every later
            // month matches. Measured: a prepaid year plus its cycles left exactly the January document
            // behind, which is the least suspicious possible number of escapees.
            ->whereDate('service_period_start', '<=', $period->from)
            ->whereDate('service_period_end', '>=', $period->to)
            ->first();
    }

    /**
     * The buyer's own documents, and the narrowing is the point.
     *
     * A creator's settlement for the same month carries a period too, and matching on the period alone would
     * hand a buyer's billing run the creator's document.
     *
     * @return Builder<InvoiceRecord>
     */
    private function buyerDocuments(Model $buyerOwner): Builder
    {
        return InvoiceRecord::query()
            ->where('owner_type', $buyerOwner->getMorphClass())
            ->where('owner_id', $buyerOwner->getKey())
            ->where('document_series', DocumentSeries::BuyerReceipt->value);
    }

    /**
     * The buyer's document for a sale the platform only ARRANGED.
     *
     * The buyer paid one amount and it contains two very different things. The goods are a supply between
     * two users that the platform is not part of; the fee is the platform's own service to the buyer. Only
     * the second carries tax here — stating tax on the whole amount would charge the buyer tax on a private
     * sale, and a stated tax is owed by whoever stated it whether or not it was ever due.
     *
     * It is issued in the commission-invoice role, not the buyer-receipt role, and that is not a technicality.
     * A receipt says "here is what you bought from us". Under intermediation the buyer bought the goods from
     * another user; what they bought from the platform is the service. The document is therefore an invoice
     * for that service which also states what was collected on the seller's behalf — which is exactly what
     * happened, and is the only role the regime permits.
     *
     * The goods amount is on the document all the same. A receipt that showed only the fee would not
     * reconcile against what the buyer was actually charged, and the first thing anybody does with a receipt
     * is compare it to their bank statement.
     *
     * @param  Money  $goodsGross  what the buyer paid the seller — passing through, not the platform's
     * @param  Money  $feeGross  what the buyer paid the platform for arranging it
     */
    public function issueIntermediated(
        Model $buyerOwner,
        FanReceiptTier $tier,
        Money $goodsGross,
        Money $feeGross,
        int $feeRateBps,
        CarbonImmutable $soldOn,
        ?string $chargeReference = null,
    ): InvoiceRecord {
        if ($goodsGross->currency !== $feeGross->currency) {
            throw new InvalidArgumentException(
                'The goods and the fee were paid in one transaction and cannot be in two currencies; adding '
                .'them would produce a total in neither.'
            );
        }

        [$feeNet, $feeTax] = $feeGross->baseFromMarkup($feeRateBps);

        return InvoiceRecord::query()->create([
            'owner_type' => $buyerOwner->getMorphClass(),
            'owner_id' => $buyerOwner->getKey(),
            'number' => $this->numbers->allocate(DocumentSeries::CommissionInvoice, $soldOn->year),
            'currency' => $feeGross->currency,
            'status' => InvoiceStatus::Open,
            'issued_at' => $soldOn,
            // The taxable base is the FEE alone. The goods are stated on the document but are not part of
            // what the platform is taxed on, because they are not the platform's supply.
            'subtotal_minor' => $feeNet->minorUnits,
            'tax_minor' => $feeTax->minorUnits,
            'total_minor' => $goodsGross->minorUnits + $feeGross->minorUnits,
            'tax_rate_bps' => $feeRateBps,
            'fan_gross_minor' => $goodsGross->minorUnits + $feeGross->minorUnits,
            'supply_regime' => SupplyRegime::Intermediation,
            // NO posture on this one, and the omission is the answer rather than the same gap one document
            // over. The posture states who sells THE SALE toward the buyer, and under intermediation that is
            // the merchant. This document is not the sale: it is the platform's own invoice for arranging
            // it, and its seller is correctly the platform. Deriving the posture from the regime here would
            // therefore make the document contradict itself -- which the seller guard says out loud, and
            // which is how this was found rather than shipped.
            //
            // Same shape as `SelfBillingEngine`, which leaves the posture off a self-billed document because
            // that one names the CREATOR as seller. In both cases the regime is the field the document can
            // carry cleanly and the posture is not.
            'document_series' => DocumentSeries::CommissionInvoice,
            'receipt_tier' => $tier,
            'settled_charge_reference' => $chargeReference,
            'seller' => $this->platformParty(),
            'buyer' => [],
            'lines' => [
                [
                    'description' => 'Purchase',
                    'quantity' => 1,
                    'unit' => 'C62',
                    'unit_price_minor' => $goodsGross->minorUnits,
                    'net_minor' => $goodsGross->minorUnits,
                    // No rate at all rather than zero: zero states that tax was considered and came to
                    // nothing, which is a claim about a supply the platform did not make.
                    'tax_rate' => null,
                ],
                [
                    'description' => 'Service fee',
                    'quantity' => 1,
                    'unit' => 'C62',
                    'unit_price_minor' => $feeNet->minorUnits,
                    'net_minor' => $feeNet->minorUnits,
                    'tax_rate' => $feeRateBps / 100,
                ],
            ],
        ]);
    }

    /**
     * The full invoice a buyer asked for after they already had a receipt.
     *
     * It is a real document with its own number stating the same sale, and the receipt the buyer already
     * holds is left exactly as it was: reaching back to change an issued document is precisely what a
     * numbered series exists to prevent, and a buyer who kept their copy would find it disagreeing with ours.
     *
     * It is marked as a restatement, because two documents now describe one sale. Everything that sums
     * documents skips a restatement — otherwise the sale is declared twice and tax is reported that nobody
     * ever took.
     *
     * @param  array<string, mixed>  $buyer  the details collected AT the request, which did not exist before it
     */
    public function reissueAsFullInvoice(
        InvoiceRecord $receipt,
        array $buyer,
        CarbonImmutable $requestedOn,
    ): InvoiceRecord {
        if ($receipt->isReissue()) {
            throw new InvalidArgumentException(
                'That document is itself the full invoice issued for an earlier receipt; asking again would '
                .'produce a chain of restatements of one sale. Re-send the existing one.'
            );
        }

        return InvoiceRecord::query()->create([
            'owner_type' => $receipt->owner_type,
            'owner_id' => $receipt->owner_id,
            'number' => $this->numbers->allocate(DocumentSeries::BuyerReceipt, $requestedOn->year),
            'currency' => $receipt->currency,
            'status' => $receipt->status,
            // The sale's own date, not the day the buyer asked. An invoice states when the supply happened;
            // dating it to the request would put it in the wrong period and misstate the tax point.
            'issued_at' => $receipt->issued_at,
            'subtotal_minor' => $receipt->subtotal_minor,
            'tax_minor' => $receipt->tax_minor,
            'total_minor' => $receipt->total_minor,
            'tax_rate_bps' => $receipt->tax_rate_bps,
            'fan_gross_minor' => $receipt->fan_gross_minor,
            'supply_regime' => $receipt->supply_regime,
            'document_series' => DocumentSeries::BuyerReceipt,
            'receipt_tier' => FanReceiptTier::FullInvoice,
            'settled_charge_reference' => $receipt->settled_charge_reference,
            // Everything that decided how the sale was TAXED comes across unchanged. A restatement that lost
            // the destination or the rate category would render as a domestic supply and tell the buyer
            // something the original never said — and the buyer is precisely the person who asked for a
            // document they could rely on.
            'oss' => $receipt->oss,
            'destination_country' => $receipt->destination_country,
            'oss_rate' => $receipt->oss_rate,
            'tax_rate_category' => $receipt->tax_rate_category,
            'tax_archetype' => $receipt->tax_archetype,
            'place_of_supply_rule' => $receipt->place_of_supply_rule,
            'reverse_charge' => $receipt->reverse_charge,
            'tax_exempt' => $receipt->tax_exempt,
            'recipient_tax_status' => $receipt->recipient_tax_status,
            'rate_matrix_version' => $receipt->rate_matrix_version,
            'reissue_of_invoice_id' => $receipt->id,
            'seller' => $receipt->seller,
            'buyer' => $buyer,
            'lines' => $receipt->lines,
        ]);
    }

    /** @return array<string, ?string> */
    private function platformParty(): array
    {
        $company = $this->config->get('billing.company');

        return Party::fromArray(is_array($company) ? $company : [])->toArray();
    }
}
