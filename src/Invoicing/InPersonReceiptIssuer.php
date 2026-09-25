<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\FanReceiptTier;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Marketplace\FanReceiptIssuer;
use Pushery\Billing\Models\InPersonSaleRecord;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * The receipt for a sale paid by card at the counter.
 *
 * Up to the small-amount threshold it is a simplified invoice: the seller, the date, what was sold, and the gross
 * with its rate as one sum (§ 33 UStDV for Germany, where the threshold is €250). Above it, the buyer at the counter
 * is still anonymous, and a full invoice needs the recipient's name and address, so the document is a payment
 * record that states the same facts without claiming to be an invoice.
 *
 * It is numbered in the seller's own invoice series, beside the invoices the package raises for orders, because it
 * is one of the seller's invoices. Its owner is the sale itself: a buyer at the counter has no account the
 * document could be filed under, and filing it under somebody would attach a sale to a person who never gave a
 * name.
 *
 * The place, the rate and the split are the ones decided when the sale went onto the reader, copied from its row.
 * Nothing is decided again here, so the receipt cannot state a different tax than the one the reader charged.
 */
final readonly class InPersonReceiptIssuer
{
    /** The default threshold, in minor units: the German small-amount invoice limit of €250, gross. */
    public const int SMALL_AMOUNT_THRESHOLD_MINOR = 25_000;

    public function __construct(
        private InvoiceNumber $numbers,
        private Repository $config,
    ) {}

    public function issue(InPersonSaleRecord $sale, CarbonInterface $paidAt): InvoiceRecord
    {
        $net = $sale->gross_minor - $sale->tax_minor;

        $invoice = InvoiceRecord::resolve();

        $invoice->fill([
            'owner_type' => InPersonSaleRecord::MORPH_ALIAS,
            'owner_id' => $sale->getKey(),
            'provider' => $sale->provider,
            'settled_charge_reference' => $sale->payment_reference,
            'number' => $this->numbers->next($paidAt),
            'currency' => $sale->currency,
            'status' => InvoiceStatus::Paid,
            'issued_at' => $paidAt,
            'delivered_on' => $paidAt,
            'subtotal_minor' => $net,
            'tax_minor' => $sale->tax_minor,
            'total_minor' => $sale->gross_minor,
            'tax_rate_bps' => $sale->tax_rate_bps,
            'receipt_tier' => $this->tierFor($sale),
            'tax_archetype' => $sale->tax_archetype,
            'sold_alongside_archetype' => $sale->sold_alongside_archetype,
            'place_of_supply_rule' => $sale->place_of_supply_rule,
            'tax_rate_category' => $sale->tax_rate_category,
            'tax_exemption_reason' => $sale->tax_exemption_reason,
            // Empty rather than null: the document has a buyer side, and a buyer at the counter leaves it blank.
            'buyer' => [],
            'lines' => [[
                'description' => $sale->description,
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $net,
                'net_minor' => $net,
                'tax_rate' => $sale->tax_rate_bps / 100,
            ]],
        ]);

        $invoice->save();

        return $invoice;
    }

    /**
     * The full invoice a buyer at the counter asked for, restating a receipt they already hold.
     *
     * A business needs its name and address on the document to deduct the tax, and any buyer may ask for more
     * than a simplified invoice. The receipt stays as it was, because an issued document is not taken back. The
     * new one takes everything that decided the tax from the receipt's frozen columns rather than deciding it
     * again, and names the receipt it restates, so whatever sums documents counts the sale once. A VAT id on it
     * changes nothing about the place: goods handed over at the counter are supplied there whoever buys them.
     *
     * @param  array<string, mixed>  $buyer  what the buyer gave when asking, at least a name and an address
     *
     * @throws InvalidArgumentException when the document is not a receipt from the counter, is already the full
     *                                  invoice for one, or the buyer gave no name or no address
     */
    public function reissueAsFullInvoice(InvoiceRecord $receipt, array $buyer, CarbonInterface $requestedOn): InvoiceRecord
    {
        if ($receipt->owner_type !== InPersonSaleRecord::MORPH_ALIAS) {
            throw new InvalidArgumentException("Invoice {$receipt->number} is not a receipt from the counter, so it is not restated here.");
        }

        if ($receipt->isReissue()) {
            throw new InvalidArgumentException("Invoice {$receipt->number} is already the full invoice for a receipt from the counter. Send it again instead of restating the sale twice.");
        }

        foreach (['name', 'line1', 'postcode', 'city', 'country'] as $field) {
            if (! is_string($buyer[$field] ?? null) || trim($buyer[$field]) === '') {
                throw new InvalidArgumentException("A full invoice names its recipient with a name and an address, and the buyer's {$field} is missing.");
            }
        }

        $carried = [];

        foreach (InvoiceRecord::FROZEN_SCALARS as $column) {
            if (! in_array($column, FanReceiptIssuer::RESTATED_DIFFERENTLY, true)) {
                $carried[$column] = $receipt->getAttribute($column);
            }
        }

        return InvoiceRecord::model()::query()->create([
            ...$carried,
            'owner_type' => $receipt->owner_type,
            'owner_id' => $receipt->owner_id,
            'number' => $this->numbers->next($requestedOn),
            'status' => $receipt->status,
            'receipt_tier' => FanReceiptTier::FullInvoice,
            'reissue_of_invoice_id' => $receipt->id,
            'buyer' => $buyer,
            'lines' => $receipt->lines,
        ]);
    }

    /** A simplified invoice up to and including the threshold, a payment record above it. */
    private function tierFor(InPersonSaleRecord $sale): FanReceiptTier
    {
        $threshold = $this->config->get('billing.card_present.small_amount_threshold_minor', self::SMALL_AMOUNT_THRESHOLD_MINOR);

        return is_int($threshold) && $sale->gross_minor <= $threshold ? FanReceiptTier::Simplified : FanReceiptTier::PaymentRecord;
    }
}
