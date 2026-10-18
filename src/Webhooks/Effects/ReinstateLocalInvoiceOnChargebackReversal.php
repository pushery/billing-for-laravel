<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\ChargebackReversed;
use Pushery\Billing\Invoicing\CorrectionTax;
use Pushery\Billing\Invoicing\InvoiceNumber;
use Pushery\Billing\Invoicing\LocalInvoiceCredit;
use Pushery\Billing\Marketplace\FanReceiptIssuer;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * Charges again, on an invoice of its own, what a locally raised invoice was credited for a chargeback the bank took back.
 *
 * {@see IssueLocalCreditNote} credits the invoice when its payment is charged back, because the money left the merchant.
 * When the bank reverses the chargeback, the money is back, and the turnover and the tax go up again in the period of the
 * reversal. The credit note cannot be taken back: it is a numbered document, and the booking export, the tax return and the
 * sales counters all read it as a reduction. So the reversal gets a document of its own, from the invoice series, that
 * charges the credited amount again and names the invoice it charges again. Every reader that sums documents then sees the
 * amount come back without being taught anything new.
 *
 * ## How much
 *
 * What the invoice stands credited at beyond what the payment still has refunded or charged back once the chargeback is
 * reversed, which `ChargebackReversed::$standingRefunded` states. A refund that still stands stays credited, and a second
 * delivery of the same reversal finds nothing left to charge.
 *
 * ## The supply it states
 *
 * The supply of the invoice it charges again, taxed the same way: the tax position, the buyer, the buyer's reference and the
 * language are copied. Charged in full, it carries that invoice's amounts and lines; charged in part, one line naming them,
 * with the net and the tax split at that invoice's rate as a credit note splits them. Its date of issue is the day of the
 * reversal, so the date of supply is stated on it: the period the invoice covered, or the day that invoice states.
 *
 * ## What it will not do
 *
 * Guess. Without the payment's standing total in the invoice's currency, the share to charge again is unknown, and nothing is
 * issued; the log names the payment, as {@see RestoreAddonPurchaseOnChargebackReversal} does for the purchase. A payment
 * without a locally raised invoice has its documents from the provider, and nothing is done here.
 */
final readonly class ReinstateLocalInvoiceOnChargebackReversal implements DedupesOnReference
{
    public function __construct(private InvoiceNumber $numbers) {}

    public function __invoke(ChargebackReversed $event): void
    {
        $invoice = LocalInvoiceCredit::invoiceFor($event->reference);

        if (! $invoice instanceof InvoiceRecord) {
            return;
        }

        $standing = $event->standingRefunded;

        if (! $standing instanceof Money || $standing->currency !== $invoice->currency) {
            Log::warning('billing: a reversed chargeback named no standing total in the currency of its invoice, so nothing was charged again', [
                'payment' => $event->reference,
                'chargeback' => $event->disputeReference,
                'invoice' => $invoice->number,
            ]);

            return;
        }

        // The lock the credit note takes, on the same row: both read what the invoice stands credited at and write a
        // document that changes it, so a refund and a reversal of one payment take turns. Inside the effect's own
        // transaction this is a savepoint, and the outer transaction holds the lock until the document commits.
        DB::transaction(function () use ($invoice, $standing): void {
            InvoiceRecord::model()::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            $this->reinstate($invoice, $standing);
        });
    }

    /** The decision and the document, both inside the caller's lock on the invoice. */
    private function reinstate(InvoiceRecord $invoice, Money $standing): void
    {
        $charge = LocalInvoiceCredit::of($invoice)->standing() - min($standing->minorUnits, $invoice->total_minor);

        if ($charge <= 0) {
            return;
        }

        $issuedAt = Carbon::now();
        $whole = $charge === $invoice->total_minor;
        $split = $whole ? null : CorrectionTax::of($invoice, $charge);

        $reinstatement = InvoiceRecord::resolve();

        $reinstatement->fill([
            'owner_type' => $invoice->owner_type,
            'owner_id' => $invoice->owner_id,
            'provider' => $invoice->provider,
            'number' => $this->numbers->next($issuedAt),
            'reinstates_invoice_id' => $invoice->id,
            'total_minor' => $charge,
            'subtotal_minor' => $whole ? $invoice->subtotal_minor : $split?->net,
            'tax_minor' => $whole ? $invoice->tax_minor : $split?->tax,
            'currency' => $invoice->currency,
            'locale' => $invoice->locale,
            'status' => InvoiceStatus::Paid,
            'issued_at' => $issuedAt,
            'buyer' => $invoice->buyer,
            // Carried over as the invoice states them: a caller writes both, and the package originates neither.
            ...$invoice->only(['buyer_reference', 'vat_note']),
            'service_period_start' => $invoice->service_period_start,
            'service_period_end' => $invoice->service_period_end,
            'delivered_on' => FanReceiptIssuer::restatedSupplyDate($invoice),
            'lines' => $whole ? $invoice->lines : $this->partLine($invoice, $charge, $split),
        ]);

        // The tax position of the supply charged again, copied as a credit note copies it: the document states the same
        // supply, and deciding its taxation again here would be a second opinion about a settled question.
        $reinstatement->fill($invoice->taxPosition());
        $reinstatement->save();
    }

    /**
     * The one line of an invoice that charges part of another again: the supply, named by that invoice's lines.
     *
     * @return list<array<string, mixed>>
     */
    private function partLine(InvoiceRecord $invoice, int $charge, ?CorrectionTax $split): array
    {
        $supplies = [];

        foreach ($invoice->lines ?? [] as $line) {
            $description = $line['description'] ?? null;

            if (is_string($description) && $description !== '') {
                $supplies[] = $description;
            }
        }

        return [[
            'description' => sprintf('%s, part of %s', $supplies === [] ? 'Supply' : implode('; ', $supplies), $invoice->number ?? 'the invoice'),
            'quantity' => 1,
            'unit_price_minor' => $split->net ?? $charge,
            'total_minor' => $charge,
            'currency' => $invoice->currency,
            ...($split instanceof CorrectionTax ? ['net_minor' => $split->net, 'tax_rate' => $split->rate] : []),
        ]];
    }

    /**
     * Once per reversed chargeback, as the effects on `ChargebackReceived` act once per dispute.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof ChargebackReversed) {
            throw new RuntimeException('ReinstateLocalInvoiceOnChargebackReversal only handles ChargebackReversed events.');
        }

        return 'reversed:'.$event->disputeReference;
    }
}
