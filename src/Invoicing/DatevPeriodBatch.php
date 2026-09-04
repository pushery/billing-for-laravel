<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Marketplace\CollectiveAccountReconciler;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\ProviderFee;
use Pushery\Billing\Models\VoucherMovementRecord;
use Pushery\Billing\ValueObjects\AccountReconciliation;
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
     * @return array{content: string, reconciliation: AccountReconciliation, invoices: int, providerFees: int, voucherMovements: int}
     */
    public function render(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = InvoiceRecord::query()
            ->whereBetween('issued_at', [$from, $to])
            ->whereNull('reissue_of_invoice_id')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $providerFees = ProviderFee::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $voucherMovements = VoucherMovementRecord::query()
            ->whereBetween('occurred_on', [$from, $to])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();

        $content = $this->export->export(
            $invoices,
            $from,
            $to,
            providerFees: $providerFees,
            voucherMovements: $voucherMovements->map(
                static fn (VoucherMovementRecord $record): VoucherMovement => $record->toMovement()
            ),
        );

        return [
            'content' => $content,
            'reconciliation' => $this->reconciler->reconcile($invoices, $content, $from, $this->currency()),
            'invoices' => $invoices->count(),
            'providerFees' => $providerFees->count(),
            'voucherMovements' => $voucherMovements->count(),
        ];
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
