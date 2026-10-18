<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\Order;

/**
 * What a locally raised invoice stands credited at: what its credit notes credited, less what the invoices that
 * charge it again charged.
 *
 * Two effects write documents that move this figure, the credit note for money going back and the invoice that
 * charges a credited amount again once a chargeback is reversed. Both decide from it, so both read it here.
 *
 * Read from the documents themselves rather than tracked on the invoice: the documents are the record, an issued
 * one cannot change, and a counter beside them would be a second version of the same fact that drifts the first
 * time one is written by anything else.
 *
 * Read under a lock, row by row, and added up here, so it belongs inside the transaction that holds the invoice row.
 * On MySQL, whose default isolation is REPEATABLE READ, a plain read answers from the snapshot taken at the
 * transaction's first plain read, which came before the wait for the invoice: a document another run committed
 * during that wait would be missing from the sum. A locking read sees it. PostgreSQL refuses `FOR UPDATE` beside an
 * aggregate, so the rows come back rather than their sum.
 */
final readonly class LocalInvoiceCredit
{
    private function __construct(
        /** What the credit notes against the invoice credited, together. */
        public int $credited,
        /** What the invoices that charge a credited amount of it again charged, together. */
        public int $reinstated,
    ) {}

    public static function of(InvoiceRecord $invoice): self
    {
        $credited = 0;

        foreach (InvoiceRecord::model()::query()->where('credited_invoice_id', $invoice->id)->lockForUpdate()->get(['total_minor']) as $note) {
            $credited += $note->total_minor;
        }

        $reinstated = 0;

        foreach (InvoiceRecord::model()::query()->where('reinstates_invoice_id', $invoice->id)->lockForUpdate()->get(['total_minor']) as $reinstatement) {
            $reinstated += $reinstatement->total_minor;
        }

        return new self($credited, $reinstated);
    }

    /**
     * The invoice this package raised for the order that payment settled, if there is one.
     *
     * Two hops rather than one, because a locally raised invoice is keyed to its ORDER while a refund or a chargeback
     * names a payment. Joining them is what tells a local document apart from a copied one: a provider's own invoice
     * has no order, so the effects that ask find nothing there and the provider's documents stand.
     */
    public static function invoiceFor(string $paymentReference): ?InvoiceRecord
    {
        $order = Order::model()::query()->where('payment_reference', $paymentReference)->first();

        if (! $order instanceof Order) {
            return null;
        }

        return InvoiceRecord::model()::query()
            ->where('order_id', $order->getKey())
            ->whereNull('credited_invoice_id')
            ->first();
    }

    /** What the invoice stands credited at now. */
    public function standing(): int
    {
        return $this->credited - $this->reinstated;
    }
}
