<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * The charge that follows a trial, and the day it is collected, as the reminder before the end of the trial names
 * them.
 *
 * It is the next invoice the bound driver previews, less the credit the cycle spends against that invoice first: the
 * local engine assembles the invoice the way its cycle will, coupon included, and Stripe previews its own total.
 * Null where nothing can be previewed and where the credit covers the whole invoice, so the reminder never names a
 * figure the charge will not have. The day is the one the preview names, which on a subscription billed in arrears
 * lies after the trial.
 */
final readonly class FirstChargePreview
{
    public function __construct(
        private UpcomingInvoice $invoices,
        private CreditLedger $credit,
    ) {}

    public function for(Model $owner): ?UpcomingInvoicePreview
    {
        $preview = $this->invoices->preview($owner);

        if (! $preview instanceof UpcomingInvoicePreview) {
            return null;
        }

        $invoice = $preview->amount;
        $balance = $this->credit->balanceFor($owner, $invoice->currency)->minorUnits;
        $charge = $invoice->minorUnits - min(max($balance, 0), $invoice->minorUnits);

        return $charge > 0 ? new UpcomingInvoicePreview($preview->date, Money::of($charge, $invoice->currency)) : null;
    }
}
