<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\SettlementRestatementBlock;
use Pushery\Billing\Enums\SettlementRestatementState;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Events\SettlementRestated;
use Pushery\Billing\Exceptions\ProductNotClassified;
use Pushery\Billing\Exceptions\SelfBillingAgreementMissing;
use Pushery\Billing\Exceptions\TaxDisclosureNotPermitted;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\SettlementRestatement;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;
use Pushery\Billing\ValueObjects\Money;

/**
 * Issues a creator's settlements again after their standing was corrected.
 *
 * ## Why a corrected standing reaches documents already issued
 *
 * A standing is routinely recorded with a start date in the past: a creator reports in April that they
 * stopped being a small business in January, or a registry confirms a standing that was pending. Every
 * settlement since that date was issued under the old standing and says something the law no longer
 * supports. Left as it is, it binds anyway. A self-billed invoice that states tax makes its recipient owe
 * that tax whether or not they may charge it (§ 14c UStG), and one that states none leaves the platform
 * without the invoice its deduction needs.
 *
 * So each of those settlements is canceled and issued again under the standing now in force at its
 * supply date. The cancellation names the settlement it takes back, which is what makes it a correction
 * of that document rather than a second one (§ 31 Abs. 5 UStDV), and both documents are dated the day they
 * are written: a correction belongs to the period it happens in, never to the declared period of the
 * supply.
 *
 * ## A pair, or nothing
 *
 * The cancellation and the replacement are written in one transaction, and everything that could refuse
 * the replacement is asked before either. A cancellation standing alone would leave the supply with no
 * document at all, which is worse than the wrong one. When a replacement cannot be issued, the settlement
 * stays as it is and the order records why ({@see SettlementRestatementBlock}).
 *
 * ## Only the creator's settlements
 *
 * The standing is a fact about the creator's own supply to the platform. A document on the buyer's side
 * states the platform's supply, which carries its full tax whatever the creator is. A creator who also buys
 * on the platform owns documents of that kind, and nothing here reads them: the orders are drawn from the
 * two settlement series alone.
 *
 * ## Queued when the standing changes, worked off by a command
 *
 * The change queues one order per settlement it may affect and does nothing else, because it happens inside
 * whatever recorded the standing, which may be a creator filling in a form. `billing:settlements:restate`
 * works the orders off and can be run again safely: a settlement is canceled at most once, and one that
 * already states what the corrected standing requires is left alone.
 */
final readonly class SettlementRestater
{
    public function __construct(
        private CreatorTaxStatusResolver $status,
        private InboundTaxMatrix $matrix,
        private SelfBillingEngine $engine,
        private SettlementCorrectionIssuer $corrections,
        private Dispatcher $events,
    ) {}

    /**
     * Queue every settlement of this creator whose supply falls on or after the date a standing took effect.
     *
     * A settlement already queued is queued again, whatever became of it: a second correction of the
     * standing is a new question about the same document, and the order answers the latest one. One that
     * was canceled already is not a settlement that stands, so it is not found.
     *
     * @return int how many settlements were queued
     */
    public function queue(Model $creator, CarbonImmutable $effectiveFrom): int
    {
        $queued = 0;

        foreach ($this->standingSettlementsSince($creator, $effectiveFrom) as $settlement) {
            $order = SettlementRestatement::model()::query()->firstOrNew(['original_invoice_id' => $settlement->id]);

            $order->fill([
                'queued_for' => $effectiveFrom,
                'state' => SettlementRestatementState::Pending,
                'blocked_reason' => null,
                'processed_at' => null,
            ])->save();

            $queued++;
        }

        return $queued;
    }

    /**
     * The orders still to be worked off: the pending ones, and the blocked ones, which a later standing, a
     * new agreement or a classified product may have unblocked.
     *
     * @return list<SettlementRestatement>
     */
    public function outstanding(): array
    {
        return array_values(SettlementRestatement::model()::query()
            ->whereIn('state', [SettlementRestatementState::Pending->value, SettlementRestatementState::Blocked->value])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** Work off one order: restate its settlement, leave it alone, or record why it cannot be restated. */
    public function process(SettlementRestatement $order, CarbonImmutable $correctedOn): SettlementRestatement
    {
        $original = InvoiceRecord::model()::query()->find($order->original_invoice_id);

        if (! $original instanceof InvoiceRecord) {
            return $this->block($order, SettlementRestatementBlock::OriginalUnresolvable, $correctedOn);
        }

        // Canceled some other way since it was queued. There is nothing left standing to restate.
        if ($this->isCanceled($original)) {
            return $this->close($order, SettlementRestatementState::Unchanged, $correctedOn);
        }

        if ($original->settlement_period !== null) {
            return $this->block($order, SettlementRestatementBlock::CollectiveSettlement, $correctedOn);
        }

        $creator = $this->ownerOf($original);

        if (! $creator instanceof Model) {
            return $this->block($order, SettlementRestatementBlock::OriginalUnresolvable, $correctedOn);
        }

        $supplyDate = CarbonImmutable::parse($original->delivered_on ?? $original->issued_at ?? $original->created_at);
        $standing = $this->status->statusAt($creator, $supplyDate);

        if ($standing === CreatorTaxStatus::Unclarified) {
            return $this->block($order, SettlementRestatementBlock::StandingUnclarified, $correctedOn);
        }

        $rate = $this->supplyRateOf($original);
        $treatment = $this->matrix->resolveOnPayout(
            // A settlement is a commission-chain document by definition — no other chain produces one — so a
            // row written before the regime was frozen onto it is one too.
            $original->supply_regime ?? SupplyRegime::CommissionChain,
            $standing,
            Money::of($original->subtotal_minor ?? $original->total_minor - ($original->tax_minor ?? 0), $original->currency),
            $rate ?? 0,
        );

        // Asked before the comparison, because without the rate the comparison is not a real one: a standing
        // that states tax computed at no rate states none, and would read as unchanged.
        if ($treatment->showsTax && $rate === null) {
            return $this->block($order, SettlementRestatementBlock::SupplyRateUnknown, $correctedOn);
        }

        if ($this->alreadyStates($original, $treatment)) {
            return $this->close($order, SettlementRestatementState::Unchanged, $correctedOn);
        }

        try {
            $this->engine->assertMayReissue($original, $creator, $treatment, $supplyDate);
        } catch (SelfBillingAgreementMissing) {
            return $this->block($order, SettlementRestatementBlock::NoSelfBillingAgreement, $correctedOn);
        } catch (TaxDisclosureNotPermitted) {
            return $this->block($order, SettlementRestatementBlock::TaxNotDisclosable, $correctedOn);
        } catch (ProductNotClassified) {
            return $this->block($order, SettlementRestatementBlock::ProductNotClassified, $correctedOn);
        }

        /** @var array{0: InvoiceRecord, 1: InvoiceRecord} $pair */
        $pair = DB::transaction(function () use ($order, $original, $creator, $treatment, $rate, $supplyDate, $correctedOn): array {
            $cancellation = $this->corrections->cancel($original, $correctedOn);
            $replacement = $this->engine->reissue($original, $creator, $treatment, $rate ?? 0, $supplyDate, $correctedOn);

            $order->fill([
                'state' => SettlementRestatementState::Restated,
                'blocked_reason' => null,
                'cancellation_invoice_id' => $cancellation->id,
                'replacement_invoice_id' => $replacement->id,
                'processed_at' => $correctedOn,
            ])->save();

            return [$cancellation, $replacement];
        });

        // After the commit, so a listener that moves money never acts on documents that were rolled back.
        $this->events->dispatch(new SettlementRestated($original, $pair[0], $pair[1]));

        return $order;
    }

    /** @return list<InvoiceRecord> */
    private function standingSettlementsSince(Model $creator, CarbonImmutable $effectiveFrom): array
    {
        $table = new (InvoiceRecord::model())()->getTable();

        return array_values(InvoiceRecord::model()::query()
            ->where('owner_type', $creator->getMorphClass())
            ->where('owner_id', $creator->getKey())
            // The two settlement series and nothing else. The creator's own receipts as a buyer carry the
            // platform's full tax whatever the creator's standing is, and must never be touched by it.
            ->whereIn('document_series', [DocumentSeries::SelfBilledInvoice->value, DocumentSeries::SettlementNote->value])
            ->whereNull('credited_invoice_id')
            ->whereNull('reissue_of_invoice_id')
            ->whereNotExists(static fn (QueryBuilder $cancellations): QueryBuilder => $cancellations
                ->selectRaw('1')
                ->from($table, 'cancellations')
                ->whereColumn('cancellations.credited_invoice_id', $table.'.id')
                ->where('cancellations.correction_kind', InvoiceCorrectionKind::Cancellation->value))
            ->where(static fn (Builder $supplied): Builder => $supplied
                // The supply date where the settlement records one, its date of issue where it does not —
                // a single settlement is dated to its supply — and the month for a collective one.
                ->where('delivered_on', '>=', $effectiveFrom)
                ->orWhere(static fn (Builder $undated): Builder => $undated
                    ->whereNull('delivered_on')
                    ->whereNull('settlement_period')
                    ->where('issued_at', '>=', $effectiveFrom))
                ->orWhere('settlement_period', '>=', $effectiveFrom->format('Y-m')))
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** Whether a cancellation already stands against this settlement. */
    private function isCanceled(InvoiceRecord $settlement): bool
    {
        return InvoiceRecord::model()::query()
            ->where('credited_invoice_id', $settlement->id)
            ->where('correction_kind', InvoiceCorrectionKind::Cancellation->value)
            ->exists();
    }

    /**
     * The rate the settled supply is taxable at, or null where the settlement never recorded it.
     *
     * Recorded since settlements carry it. Before that only a settlement that stated tax named a rate, in its
     * column or, earlier still, on its line; one that stated none named none, and no rate is invented for it.
     */
    private function supplyRateOf(InvoiceRecord $settlement): ?int
    {
        if ($settlement->supply_rate_bps !== null) {
            return $settlement->supply_rate_bps;
        }

        if (($settlement->tax_minor ?? 0) <= 0) {
            return null;
        }

        if ($settlement->tax_rate_bps !== null) {
            return $settlement->tax_rate_bps;
        }

        $lines = $settlement->getAttribute('lines');
        $rate = is_array($lines) && is_array($lines[0] ?? null) ? ($lines[0]['tax_rate'] ?? null) : null;

        return is_int($rate) || is_float($rate) ? (int) round($rate * 100) : null;
    }

    /** Whether the settlement already says what this treatment says, so issuing it again would change nothing. */
    private function alreadyStates(InvoiceRecord $settlement, InboundTaxTreatment $treatment): bool
    {
        return $settlement->settlement_document_type === $treatment->document
            && ($settlement->tax_minor ?? 0) === $treatment->taxAmount->minorUnits
            && (bool) $settlement->reverse_charge === $treatment->reverseChargeToRecipient
            && (bool) $settlement->tax_exempt === $treatment->exempt
            && $settlement->tax_exemption_reason === $treatment->exemptionReason;
    }

    /** The creator a settlement names, or null where the stored type resolves to no model. */
    private function ownerOf(InvoiceRecord $settlement): ?Model
    {
        $type = $settlement->getAttribute('owner_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class)) {
            return null;
        }

        $owner = $settlement->owner;

        return $owner instanceof Model ? $owner : null;
    }

    private function block(SettlementRestatement $order, SettlementRestatementBlock $reason, CarbonImmutable $at): SettlementRestatement
    {
        $order->fill(['state' => SettlementRestatementState::Blocked, 'blocked_reason' => $reason, 'processed_at' => $at])->save();

        return $order;
    }

    private function close(SettlementRestatement $order, SettlementRestatementState $state, CarbonImmutable $at): SettlementRestatement
    {
        $order->fill(['state' => $state, 'blocked_reason' => null, 'processed_at' => $at])->save();

        return $order;
    }
}
