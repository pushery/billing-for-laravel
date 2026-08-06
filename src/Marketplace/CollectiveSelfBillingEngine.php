<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\CollectiveSettlementSpansTaxCategories;
use Pushery\Billing\Exceptions\SettlementTransactionOutsidePeriod;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;
use Pushery\Billing\ValueObjects\SettlementTransaction;

/**
 * Settles a creator's whole month into ONE self-billing document, dated to the last day of the month, with a
 * line per transaction — the collective run behind the monthly credit note.
 *
 * The single date is the point. A per-transaction document would scatter a creator's supplies across the
 * month; dating the collective on the month-end (Ultimo) puts the platform's expense, its input tax and the
 * matching output turnover in the SAME period, which is what § 15 UStG's "supply received AND invoice held"
 * needs, and makes the document total equal to the month's payout run — reconciliation by construction
 * rather than a search. The 6-month issuance limit is met structurally as a consequence.
 *
 * It does not re-decide tax. Each transaction is planned through {@see SelfBillingEngine::plan()} — the same
 * status-at-supply-date resolution, matrix and guards a single settlement runs, minus the number — so a hold
 * falls out of the document (it carries no invoice), and a self-billed line clears the disclosure whitelist
 * exactly as a single one would. A single number is drawn for the whole document. Running the same month
 * twice finds the first document rather than minting a second.
 *
 * Different RATES in one document are fine — the writer breaks VAT down per rate — but a month that spans two
 * VAT CATEGORIES (a creator who crosses the small-business threshold mid-month, exempt before and taxed
 * after) is refused rather than issued with a document-level category that would misstate half the lines;
 * that month waits for per-line category rendering.
 */
final readonly class CollectiveSelfBillingEngine
{
    public function __construct(
        private SelfBillingEngine $engine,
        private DocumentNumberAllocator $numbers,
        private MerchantPartyResolver $merchantParty,
        private Repository $config,
    ) {}

    /**
     * @param  iterable<SettlementTransaction>  $transactions
     */
    public function settleMonth(
        Model $creator,
        SupplyRegime $regime,
        int $year,
        int $month,
        iterable $transactions,
    ): ?InvoiceRecord {
        $period = sprintf('%04d-%02d', $year, $month);

        $lines = [];
        $subtotalMinor = 0;
        $taxMinor = 0;
        $totalMinor = 0;
        $series = null;
        $exempt = null;
        $reverseCharge = null;
        $currency = null;

        foreach ($transactions as $transaction) {
            // WHICH period this transaction counts in, asked before it is priced.
            //
            // A caller groups its transactions by month and hands them over; nothing until now checked that
            // what it handed over actually belongs to the month being settled. That silence is affordable
            // while "supplied in" and "counted in" are the same date and stops being affordable the moment
            // they diverge — a term paid up front counts in the month the money arrived while its service is
            // rendered across the year, and a run that grouped by supply date would settle the same turnover
            // in a month the buyer's side already taxed elsewhere.
            //
            // Refused rather than quietly moved. Assigning it here would make this engine a second place the
            // periodisation is decided, and the caller's grouping would silently stop mattering.
            if ($transaction->countedOn()->format('Y-m') !== $period) {
                throw SettlementTransactionOutsidePeriod::make($period, $transaction->countedOn()->format('Y-m'));
            }

            $plan = $this->engine->plan(
                $creator,
                $regime,
                $transaction->net,
                $transaction->commission,
                $transaction->supplyRateBps,
                $transaction->supplyDate,
            );
            // A hold carries no invoice — and no treatment — so it falls out of the collective document
            // entirely; the 6-month trigger, not this run, is what eventually surfaces a transaction that
            // never gets settled. A non-hold plan always carries both its treatment and its series, so this
            // one check is the hold filter, and the series is narrowed by the accumulator and the final guard.
            $treatment = $plan->treatment;

            if (! $treatment instanceof InboundTaxTreatment) {
                continue;
            }

            // The document role is one per creator-month (a creator is self-billed or settled, not both), and
            // the VAT category must be single because it is a document-level property here. A mid-month
            // threshold flip is the one case that breaks the category rule; it is refused, not mis-rendered.
            $series ??= $plan->series;
            $currency ??= $transaction->net->currency;

            if ($exempt === null) {
                $exempt = $treatment->exempt;
                $reverseCharge = $treatment->reverseChargeToRecipient;
            } elseif ($exempt !== $treatment->exempt || $reverseCharge !== $treatment->reverseChargeToRecipient) {
                throw CollectiveSettlementSpansTaxCategories::make($period);
            }

            // The line net is the payout net of its own tax; the sums aggregate the per-line values already
            // rounded by the matrix, so the document total is the payout run to the cent.
            $lineNet = $treatment->payoutAmount->minus($treatment->taxAmount);

            $lines[] = [
                'description' => $transaction->description ?? 'Platform settlement',
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $lineNet->minorUnits,
                'net_minor' => $lineNet->minorUnits,
                'tax_rate' => $treatment->showsTax ? $transaction->supplyRateBps / 100 : 0.0,
                // The service time the line states — a per-line date, admissible as the supply month too.
                'service_date' => $transaction->supplyDate->format('Y-m-d'),
            ];

            $subtotalMinor += $lineNet->minorUnits;
            $taxMinor += $treatment->taxAmount->minorUnits;
            $totalMinor += $treatment->payoutAmount->minorUnits;
        }

        // Every transaction was a hold, or there were none: no document, nothing paid.
        if (! $series instanceof DocumentSeries || $currency === null) {
            return null;
        }

        // Idempotency: one document per creator and period. A second run for the same month returns the first
        // rather than drawing a second number.
        $existing = InvoiceRecord::query()
            ->where('owner_type', $creator->getMorphClass())
            ->where('owner_id', $creator->getKey())
            ->where('settlement_period', $period)
            ->first();

        if ($existing instanceof InvoiceRecord) {
            return $existing;
        }

        // Built from the period string, whose non-null constructor sidesteps create()'s nullable return; the
        // month-end at day start is the Ultimo the whole document dates to.
        $ultimo = new CarbonImmutable($period.'-01')->endOfMonth()->startOfDay();

        return InvoiceRecord::query()->create([
            'owner_type' => $creator->getMorphClass(),
            'owner_id' => $creator->getKey(),
            'number' => $this->numbers->allocate($series, $year),
            'currency' => $currency,
            'status' => InvoiceStatus::Open,
            'issued_at' => $ultimo,
            'subtotal_minor' => $subtotalMinor,
            'tax_minor' => $taxMinor,
            'total_minor' => $totalMinor,
            'reverse_charge' => $reverseCharge ?? false,
            'tax_exempt' => $exempt ?? false,
            // The frozen tax characteristics are deliberately NOT set here, and the omission is the answer
            // rather than the same gap one document over. A collective settlement is one document over many
            // transactions, and the archetype is a fact about each of them: a creator who sold a download and
            // a commissioned work in the same month has no single one. Writing whichever came first would
            // make the document state something false about the other half, and it would look filled in.
            //
            // A column that is sometimes right is worse than an empty one — the empty one sends a reader to
            // the lines, where the answer actually lives, and the sometimes-right one does not.
            //
            // The consequence belongs to whoever builds the reporting run: grouping by archetype over
            // settlement DOCUMENTS holds for per-transaction settlements and not for these. Here it is the
            // lines, or the single settlements behind them.
            'supply_regime' => $regime,
            'settlement_document_type' => $series === DocumentSeries::SelfBilledInvoice
                ? SettlementDocumentType::SelfBilledInvoice
                : SettlementDocumentType::SettlementNote,
            'document_series' => $series,
            'settlement_period' => $period,
            // The parties reversed exactly as a single settlement: the creator the seller, the platform the buyer.
            'seller' => $this->merchantParty->partyFor($creator)->toArray(),
            'buyer' => $this->platformParty(),
            'lines' => $lines,
        ]);
    }

    /** @return array<string, ?string> */
    private function platformParty(): array
    {
        $company = $this->config->get('billing.company');

        return Party::fromArray(is_array($company) ? $company : [])->toArray();
    }
}
