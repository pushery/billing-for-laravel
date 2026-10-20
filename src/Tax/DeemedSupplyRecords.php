<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\LazyCollection;
use Pushery\Billing\Contracts\SuppliesConsignmentNumbers;
use Pushery\Billing\Enums\DeemedSupplyOfGoods;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;

/**
 * The records the platform keeps of the supplies of goods it is treated as making itself, one row per document.
 *
 * A supplier in the Union scheme or the import scheme keeps records of every supply it declares there and makes them
 * available electronically on request (Art. 369k and 369x VAT Directive), with the content Art. 63c of Implementing
 * Regulation 282/2011 lists. Nothing here is kept twice: every field is read from the buyer's document of the supply
 * and from its corrections, which are frozen when they are issued and retained for as long as the records have to
 * be, and from the consignment the sale's row froze before the money moved.
 *
 * ## One row per document
 *
 * The sale is one row and each correction is another, with its amounts negative and the number of the document it
 * corrects. That is how the records show a later increase or reduction of the taxable amount and a return of the
 * goods with its amount and rate: as the document that stated it.
 *
 * ## What the columns are
 *
 * - `scheme`: `union` or `import`, the return the supply is declared in.
 * - `member_state_of_consumption`, `transport_begins` and `transport_ends`: where the supply took place and where the
 *   goods set out from and arrived; an imported consignment's transport begins outside the Union, written `imported`.
 * - `goods` and `quantity`: the description the buyer's document names and the quantity the consignment held. A
 *   correction names the goods of the document it corrects, and no quantity, which nothing states for it.
 * - `supplied_on`: the date of the supply, the moment the payment was accepted (Art. 66a VAT Directive), on a
 *   correction the date of the supply it corrects.
 * - `taxable_amount`, `vat_rate`, `vat` and `currency`: the amounts the document stated.
 * - `payment_received_on` and `payment_amount`: the payment, which for such a supply is accepted at the moment the
 *   supply is made, so no payment on account precedes it; on a correction, what went back.
 * - `document`, `document_tier` and `corrects`: the document the platform issued, and the one a correction corrects.
 * - `return_reason`: why a correction reduced the amount, `repaid` for money given back.
 * - `order_reference`: the payment the sale was made with, which identifies the transaction.
 * - `consignment_number`: the number an imported consignment shipped under, where {@see SuppliesConsignmentNumbers}
 *   is bound.
 */
final readonly class DeemedSupplyRecords
{
    /** The columns of a row, in the order they are written. */
    public const array COLUMNS = [
        'scheme', 'document', 'corrects', 'document_tier', 'supplied_on', 'member_state_of_consumption', 'goods',
        'quantity', 'taxable_amount', 'vat_rate', 'vat', 'currency', 'payment_received_on', 'payment_amount',
        'transport_begins', 'transport_ends', 'return_reason', 'order_reference', 'consignment_number',
    ];

    /** How many documents one read holds. */
    private const int PAGE = 500;

    /**
     * Every document of such a supply issued between the two moments, the sale and its corrections, oldest first.
     *
     * @return LazyCollection<int, array<string, string>>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): LazyCollection
    {
        $container = Container::getInstance();
        $numbers = null;

        if ($container->bound(SuppliesConsignmentNumbers::class)) {
            $numbers = $container->make(SuppliesConsignmentNumbers::class);
        }

        return InvoiceRecord::model()::query()
            ->whereNotNull('deemed_supply')
            ->whereIn('document_series', [DocumentSeries::BuyerReceipt->value, DocumentSeries::BuyerReceiptCorrection->value])
            ->whereBetween('issued_at', [$from, $to])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->lazy(self::PAGE)
            ->map(fn (InvoiceRecord $document): array => $this->rowFor($document, $numbers));
    }

    /**
     * The row of one document.
     *
     * @return array<string, string>
     */
    private function rowFor(InvoiceRecord $document, ?SuppliesConsignmentNumbers $numbers): array
    {
        $correcting = $document->isCorrection();
        $sign = $correcting ? -1 : 1;
        $currency = $document->currency;
        $imported = $document->deemed_supply === DeemedSupplyOfGoods::ImportedConsignment;
        $reference = $document->settled_charge_reference;
        // A correction names the goods and the date of the supply it corrects, which only that document states.
        $supply = $correcting ? $this->corrected($document) : $document;
        $lines = $supply?->getAttribute('lines');
        $goods = is_array($lines) && is_array($lines[0] ?? null) && is_string($lines[0]['description'] ?? null) ? $lines[0]['description'] : '';
        $consignmentNumber = null;

        if ($imported && $numbers instanceof SuppliesConsignmentNumbers && $reference !== null && $document->provider !== null) {
            $consignmentNumber = $numbers->consignmentNumberFor($document->provider, $reference);
        }

        return [
            'scheme' => $imported ? 'import' : 'union',
            'document' => (string) $document->number,
            'corrects' => (string) $document->credited_invoice_number,
            'document_tier' => $document->receipt_tier->value ?? '',
            'supplied_on' => $supply?->delivered_on?->toDateString() ?? '',
            'member_state_of_consumption' => (string) $document->destination_country,
            'goods' => $goods,
            // How many items a correction gave back is not stated anywhere, so it claims none.
            'quantity' => $correcting ? '' : (string) ($this->chargeOf($document)?->goodsConsignment()->quantity ?? 1),
            'taxable_amount' => new Money($sign * ($document->subtotal_minor ?? 0), $currency)->toDecimal(),
            'vat_rate' => number_format(($document->tax_rate_bps ?? 0) / 100, 2, '.', ''),
            'vat' => new Money($sign * ($document->tax_minor ?? 0), $currency)->toDecimal(),
            'currency' => $currency,
            'payment_received_on' => $document->issued_at?->toDateString() ?? '',
            'payment_amount' => new Money($sign * $document->total_minor, $currency)->toDecimal(),
            'transport_begins' => $imported ? 'imported' : (string) $document->goods_located_in,
            'transport_ends' => (string) $document->destination_country,
            'return_reason' => $document->tax_base_change_reason->value ?? '',
            'order_reference' => (string) $reference,
            'consignment_number' => (string) $consignmentNumber,
        ];
    }

    /** The document a correction corrects, as it was issued. */
    private function corrected(InvoiceRecord $correction): ?InvoiceRecord
    {
        return InvoiceRecord::model()::query()->find($correction->credited_invoice_id);
    }

    /** The sale's row, which keeps the consignment the quantity is read from. */
    private function chargeOf(InvoiceRecord $document): ?MerchantCharge
    {
        if ($document->provider === null || $document->settled_charge_reference === null) {
            return null;
        }

        return MerchantCharge::model()::query()
            ->where('provider', $document->provider)
            ->where('charge_reference', $document->settled_charge_reference)
            ->first();
    }
}
