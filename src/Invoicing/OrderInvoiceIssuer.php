<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Support\InvoiceNumberSequence;
use Throwable;

/**
 * Raises the invoice for an order a local engine just collected.
 *
 * A provider-driven driver never needs this: Stripe issues the document and the package copies it. A
 * local engine has no such source — it has an order, its lines, and the money it took — so the invoice is
 * raised here or it does not exist. Until it did not, and the invoices screen was empty for every local
 * driver while the money moved perfectly well.
 *
 * ## The lines are copied, never referenced
 *
 * An invoice states what was sold at the moment it was sold. Referencing the order's rows would let a
 * later price change, a corrected description or a deleted line rewrite a document that was already
 * issued — silently, and for every historical invoice at once. So the lines are frozen into the record's
 * own JSON, and the order can afterwards be whatever it becomes.
 *
 * ## One invoice per order, enforced by the database
 *
 * A cycle can be processed more than once. Invoice numbers are gapless and immutable, so a duplicate is
 * not a mess to tidy up later — it is a second numbered document asserting a charge that happened once,
 * and the number it consumed can never be reissued. The unique constraint on `order_id` is what makes the
 * second attempt lose rather than mint, and the insert is attempted rather than checked-then-inserted,
 * because between a check and an insert is exactly where a concurrent run fits.
 *
 * ## What this deliberately does NOT state
 *
 * **No tax.** `tax_minor` is left null rather than written as zero. A driver whose provider does not
 * determine tax (`supportsProviderTax: false`) has no basis for either number, and zero is not the absence
 * of a claim — it is the claim that no tax was due, which nothing here established. Determining place of
 * supply, rate and archetype for a local cycle is its own work with legal weight, tracked separately; an
 * invoice that states a tax nobody computed is worse than one that states none.
 */
final readonly class OrderInvoiceIssuer
{
    public function __construct(
        private InvoiceNumberSequence $numbers,
        private Repository $config,
    ) {}

    /**
     * Issue the invoice for this order, or return null when it already has one.
     *
     * Never throws into the billing cycle. The money is already collected at this point, and a failure to
     * produce the document must not undo that or stop the run — a missing invoice is recoverable, a cycle
     * that reports failure after taking the money is not.
     */
    public function issue(Order $order): ?InvoiceRecord
    {
        try {
            return $this->raise($order);
        } catch (Throwable) {
            return null;
        }
    }

    private function raise(Order $order): ?InvoiceRecord
    {
        if (InvoiceRecord::query()->where('order_id', $order->getKey())->exists()) {
            return null;
        }

        $issuedAt = Carbon::now();

        return InvoiceRecord::query()->create([
            'owner_type' => $order->owner_type,
            'owner_id' => $order->owner_id,
            'provider' => $order->provider,
            'order_id' => $order->getKey(),
            'number' => $this->number($issuedAt),
            'total_minor' => $order->total_minor,
            'subtotal_minor' => $order->total_minor,
            'currency' => $order->currency,
            'status' => InvoiceStatus::Paid,
            'issued_at' => $issuedAt,
            'lines' => $this->frozenLines($order),
        ]);
    }

    /**
     * The lines as they stood, in the order they were billed.
     *
     * A discount or a credit line carries a negative total and is kept as such: an invoice that shows the
     * gross and quietly nets the discount away tells the reader a price that was never charged.
     *
     * @return list<array<string, mixed>>
     */
    private function frozenLines(Order $order): array
    {
        // The arrow function's parameter is typed like any other, because the type-coverage floor counts it
        // like any other — and here it is also the only thing saying WHAT is being frozen. A line whose
        // shape nobody states is one somebody later reads a different column off, and this array is copied
        // onto an issued document that must not change afterwards.
        return array_values($order->items()->orderBy('id')->get()->map(static fn (OrderItem $item): array => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unit_price_minor,
            'total_minor' => $item->total_minor,
            'currency' => $item->currency,
            'type' => $item->type->value,
        ])->all());
    }

    /**
     * A number in the shape a real document carries: prefix, year, running part.
     *
     * Scoped per year so the running part restarts, which is what makes a number readable rather than an
     * ever-growing integer. Gaps are harmless — a sequence that skipped a number is not a defect — but a
     * number issued twice is unrecoverable, which is why the sequence locks rather than counts rows.
     */
    private function number(Carbon $issuedAt): string
    {
        $prefix = $this->config->get('billing.invoices.number_prefix', 'INV');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'INV';
        $year = $issuedAt->format('Y');

        return sprintf('%s-%s-%07d', $prefix, $year, $this->numbers->next("invoice:{$prefix}:{$year}"));
    }
}
