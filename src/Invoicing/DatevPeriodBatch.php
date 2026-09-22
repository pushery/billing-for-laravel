<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Marketplace\CollectiveAccountReconciler;
use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\ProviderFee;
use Pushery\Billing\Models\VoucherMovementRecord;
use Pushery\Billing\Support\CreditConsumption;
use Pushery\Billing\ValueObjects\AccountReconciliation;
use Pushery\Billing\ValueObjects\CreditMovement;
use Pushery\Billing\ValueObjects\VoucherMovement;

/**
 * Everything a period's booking batch is made of, assembled once for every caller.
 *
 * ## Why this is a class and not two copies of a query
 *
 * The assembly is where this export has gone wrong twice, and both times silently. `DatevExport::export()`
 * has taken provider fees as its fifth argument for as long as the fee accounts have existed, and the only
 * production caller passed three arguments — so the accounts were configured, the booking was written, and
 * every real monthly batch contained zero provider fees. The voucher movements went the same way. Nothing
 * was red either time, because the tests that prove those bookings pass the rows in themselves.
 *
 * A second caller re-deriving these three queries would reintroduce exactly that, and the drift would again
 * be invisible: the batch is structurally valid, imports cleanly, and is short by a category nobody
 * enumerates. So there is one assembly, and adding a fourth source means adding it here.
 *
 * ## The two exclusions are not filters, they are corrections
 *
 * A draft has no `issued_at` and therefore no booking date. A restatement — the full invoice a buyer asked
 * for after their receipt — is the same sale stated a second time, and booking it doubles the revenue AND
 * the tax in the books.
 */
final readonly class DatevPeriodBatch
{
    public function __construct(
        private DatevExport $export,
        private CollectiveAccountReconciler $reconciler,
        private Repository $config,
    ) {}

    /**
     * Render the period, and report what went into it.
     *
     * The counts come back with the content rather than being recomputed by the caller, because a caller
     * that counts separately can report a number the file does not contain — and a number in an operator's
     * confirmation line is read as a description of the file.
     *
     * ## The reconciliation is computed HERE, and that placement is the point
     *
     * Whether the merchant payables in the emitted file tie out to the sub-ledger can only be asked of the
     * documents this file was built from. A caller doing it would have to re-query the period, and every
     * difference between its query and the one above — a bound snapped differently, a filter only one of
     * them applies — would surface as an accounting difference that is an artifact of the reader. That is
     * the same defect the counts were moved in here for, on a figure an accountant acts on.
     *
     * So the documents never leave this method, and the report states what the file says about itself.
     * A caller decides what a difference MEANS to it — an exit code, a warning on a screen — but no caller
     * has to know how to establish one.
     *
     * @return array{content: string, reconciliation: AccountReconciliation, invoices: int, providerFees: int, voucherMovements: int, creditMovements: int, unbookedCreditMinor: int, currency: string}
     */
    public function render(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = InvoiceRecord::model()::query()
            ->whereBetween('issued_at', [$from, $to])
            ->whereNull('reissue_of_invoice_id')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $providerFees = ProviderFee::model()::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $voucherMovements = VoucherMovementRecord::model()::query()
            ->whereBetween('occurred_on', [$from, $to])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();

        // The fourth source, and the docblock above says why it is added HERE rather than at a call site.
        $creditEntries = CreditLedgerEntry::model()::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $creditMovements = $this->splitSpends($creditEntries);

        $content = $this->export->export(
            $invoices,
            $from,
            $to,
            providerFees: $providerFees,
            voucherMovements: $voucherMovements->map(
                static fn (VoucherMovementRecord $record): VoucherMovement => $record->toMovement()
            ),
            creditMovements: $creditMovements,
        );

        // What a movement leaves UNBOOKED, signed the way the movement is.
        //
        // A proration grant is the whole of itself: nothing books it, because a booking would invent a
        // liability nobody owes. An offset leaves behind only the share it took out of such a grant — the
        // rest settled a receivable against credit that was really paid for. The sign is what makes the two
        // cancel: grant a proration credit and spend it in the same period and the figure is zero, which is
        // the truth about that period rather than an arithmetic accident. Nothing was booked, and nothing
        // needed to be.
        $unbookedMinor = static fn (CreditMovement $movement): int => $movement->booksAgainstMoney()
            ? -$movement->unpaidShareMinor()
            : $movement->amount->minorUnits;

        // Counted by whether a ROW was produced, not by the reason — and the movement answers that, so this
        // count and the export's decision to emit cannot drift apart. An offset paid for entirely out of
        // proration credit books nothing, and counting it would state a booking the file does not contain.
        $bookedCredit = $creditMovements->filter(
            static fn (CreditMovement $movement): bool => $movement->booksARow()
        );

        return [
            'content' => $content,
            'reconciliation' => $this->reconciler->reconcile($invoices, $content, $from, $this->currency()),
            'invoices' => $invoices->count(),
            'providerFees' => $providerFees->count(),
            'voucherMovements' => $voucherMovements->count(),
            'creditMovements' => $bookedCredit->count(),
            // Stated, never silently dropped. A proration credit grants spendable balance with no payment
            // and no credit note behind it, so the pair of accounts it would book against is an open
            // question rather than a lookup. Reporting the amount keeps the batch produceable
            // and puts the gap in front of the person who runs it — a zero here and a zero because nothing
            // happened look identical, and only this figure tells them apart.
            //
            // IT IS NOT THE SUM OF THE UNBOOKABLE GRANTS, and that is the correction of 2026-09-16. Spend
            // such a grant and its share leaves this figure again: nothing was booked either way, so a period
            // that granted and redeemed the same credit owes the books nothing and now says so. Read as a
            // grant total it would have grown forever while describing a gap that had already closed.
            'unbookedCreditMinor' => $creditMovements->sum($unbookedMinor),
            // Carried with the figure rather than re-derived by each caller. `unbookedCreditMinor` is minor
            // units and means nothing without it, and a surface that reads the config itself is a second
            // answer to a question this class already answered — the reconciliation above uses the same one.
            'currency' => $this->currency(),
        ];
    }

    /**
     * The period's entries as movements, with every OFFSET carrying how much of it came out of credits
     * nobody paid for.
     *
     * ## Why the split happens here and not in the export
     *
     * A balance is fungible: one negative entry spends whatever is in it. The books need the halves apart,
     * because a paid top-up is money held against a promise while a proration credit is consideration being
     * given back, and those do not touch the same account. Deciding which is which needs the owner's WHOLE
     * ledger rather than this period's slice — a credit granted in March and spent in June is the ordinary
     * case, and a period-bounded replay would report that June spend as having consumed nothing.
     *
     * ## Only an OFFSET is asked, and that is a statement about what FIFO means
     *
     * A reversal and a returned offset are also negative, and replaying them as consumption would answer a
     * question nobody asked: they undo one identified movement, not "some of the balance". A clawed-back
     * top-up returns the money that top-up brought in, whatever happens to sit at the front of the queue —
     * so attributing lots to it would take a real booking (money in transit) and call part of it unbookable.
     * They still COUNT as consumption inside the replay, because they really did take credit out of the
     * balance; what they do not get is an attribution of their own.
     *
     * ## The history query is a deliberate SUPERSET
     *
     * The owner is a morph pair, and an exact `(type, id)` tuple filter is not portable across the three
     * engines this package supports. Two `whereIn`s therefore fetch a superset — every id that occurs against
     * every type that occurs — and the grouping narrows it to the exact triple. The superset is safe because
     * the grouping is what decides; a tuple filter that worked on one engine and silently matched nothing on
     * another would not be, and that failure reads as "this spend consumed nothing".
     *
     * @param  Collection<int, CreditLedgerEntry>  $entries
     * @return Collection<int, CreditMovement>
     */
    private function splitSpends(Collection $entries): Collection
    {
        $offsets = $entries->filter(static fn (CreditLedgerEntry $entry): bool => self::isOffset($entry));

        if ($offsets->isEmpty()) {
            return $entries->map(static fn (CreditLedgerEntry $entry): CreditMovement => $entry->toMovement());
        }

        $history = CreditLedgerEntry::model()::query()
            ->whereIn('owner_type', $offsets->pluck('owner_type')->unique()->values()->all())
            ->whereIn('owner_id', $offsets->pluck('owner_id')->unique()->values()->all())
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (CreditLedgerEntry $entry): string => self::balanceKey($entry));

        return $entries->map(static function (CreditLedgerEntry $entry) use ($history): CreditMovement {
            if (! self::isOffset($entry)) {
                return $entry->toMovement();
            }

            // A default rather than a null check, and the difference is not style: the entry being split is
            // itself in the history — the query was built from these very owners — so a miss cannot happen,
            // and a branch written for it is one no run can enter. This package's coverage floor is where
            // that stops being a philosophical point.
            $balance = $history->get(self::balanceKey($entry), new Collection);

            return $entry->toMovement(CreditConsumption::unpaidShare(
                CreditConsumption::of($entry, array_values($balance->all())),
                $entry->currency,
            ));
        });
    }

    /** A spend of the fungible balance against something already invoiced — the one movement FIFO answers for. */
    private static function isOffset(CreditLedgerEntry $entry): bool
    {
        return $entry->amount_minor < 0
            && in_array($entry->reason, [CreditReason::ChargeOffset, CreditReason::ProviderInvoiceOffset], true);
    }

    /** One owner's balance in one currency — the unit a FIFO replay is meaningful over. */
    private static function balanceKey(CreditLedgerEntry $entry): string
    {
        return $entry->owner_type.'|'.$entry->owner_id.'|'.$entry->currency;
    }

    /**
     * The currency the books are kept in.
     *
     * One installation-wide setting rather than a per-document read: a booking batch states one collective
     * account balance, and a sub-ledger total in a second currency could not be compared with it at all.
     */
    private function currency(): string
    {
        $currency = $this->config->get('billing.currency', 'EUR');

        return is_string($currency) && $currency !== '' ? $currency : 'EUR';
    }
}
