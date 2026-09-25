<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\InPersonSaleStatus;
use Pushery\Billing\Events\InPersonSalePaid;
use Pushery\Billing\Exceptions\InPersonPaymentMismatch;
use Pushery\Billing\Invoicing\InPersonReceiptIssuer;
use Pushery\Billing\Models\InPersonSaleRecord;

/**
 * Marks a sale at the counter as paid and issues its receipt, once the provider has confirmed the card payment.
 *
 * The sale is documented now and not when it went onto the reader, because until the card was presented nothing
 * had been sold. The receipt and the status change are written together, so a redelivered confirmation finds the
 * sale paid and issues nothing a second time.
 *
 * A confirmation for a payment the counter path has no row for is left alone: it is somebody else's payment.
 */
final readonly class DocumentInPersonSale
{
    public function __construct(private InPersonReceiptIssuer $receipts) {}

    public function __invoke(InPersonSalePaid $event): void
    {
        $sale = InPersonSaleRecord::model()::query()
            ->where('provider', $event->provider)
            ->where('payment_reference', $event->paymentReference)
            ->first();

        if (! $sale instanceof InPersonSaleRecord || $sale->status === InPersonSaleStatus::Paid) {
            return;
        }

        if (! $event->amount->equals($sale->gross())) {
            throw InPersonPaymentMismatch::forPayment($event->paymentReference, $event->amount, $sale->gross());
        }

        $paidAt = Carbon::now();

        $sale->getConnection()->transaction(function () use ($sale, $paidAt): void {
            $receipt = $this->receipts->issue($sale, $paidAt);

            $sale->status = InPersonSaleStatus::Paid;
            $sale->paid_at = $paidAt;
            $sale->invoice_id = $receipt->id;
            $sale->save();
        });
    }
}
