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
 * ## The tax AND the net are stated only where they were established
 *
 * `tax_minor` was left null on every document this ever raised, and that was honest rather than complete:
 * a driver whose provider does not determine tax (`supportsProviderTax: false`) has no result to copy, and
 * zero is not the absence of a claim — it is the claim that no tax was due.
 *
 * {@see OrderTaxBasis} now determines it where the basis exists, and refuses where it does not. When it
 * answers, the document freezes the whole basis beside the figure — archetype, place of supply, rate band,
 * exemption, destination and the period supplied — which is what makes the figure defensible years later
 * and what {@see Guards\TaxWithoutBasisGuard} insists on.
 *
 * WHEN IT REFUSES, THE NET IS NOW NULL TOO, AND THAT IS THE HALF THIS USED TO GET WRONG. The sentence
 * here read "a null tax, a subtotal equal to the total, and no characteristics" — and a subtotal equal to
 * the total is the same kind of claim as a zero tax: it says the supply was untaxed. True under a
 * small-business regime or an exemption, false under a taxable supply, and nobody determined which. The
 * argument that made zero unacceptable makes this unacceptable for exactly the same reason, one column
 * over.
 *
 * Nothing a reader sees changes, and that was measured rather than hoped: `InvoiceDocumentRenderer` falls
 * back to `total_minor - (tax_minor ?? 0)`, and the e-invoice path in {@see Concerns\NormalizesInvoiceModel}
 * sums the frozen lines whenever there are any — which this issuer always writes.
 *
 * ## A basis that fails must never cost the document
 *
 * The determination runs in its own try/catch, and a throw leaves the document without tax rather than
 * without existence. A misconfigured archetype is a configuration defect that `billing:doctor` reports;
 * losing the numbered document for money that already moved is not recoverable in the same way.
 */
final readonly class OrderInvoiceIssuer
{
    public function __construct(
        private InvoiceNumberSequence $numbers,
        private Repository $config,
        /**
         * REQUIRED, and it was optional for about an hour.
         *
         * A nullable parameter with a default is not auto-resolved: `Container::resolveClass()` returns the
         * default whenever one exists and the class is not explicitly bound, without ever attempting to
         * build it. So every container-resolved issuer held null, the determination never ran, and the whole
         * seam shipped inert — with the test suite green, because a document with no tax is precisely what
         * this issuer produced before and every existing arm asserts exactly that.
         *
         * An optional dependency also says the wrong thing about this class. An issuer that cannot consult
         * the basis cannot decide whether a figure may be stated, and the honest shape of that is a
         * constructor that will not build without one.
         */
        private OrderTaxBasis $basis,
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
        $tax = $this->determined($order);

        // Filled in two passes rather than created from one spread literal, and the reason is a type
        // rather than a taste: the model's own property list is what makes a create() literal checkable,
        // and spreading a computed array into it erases that for every key at once. Two fills keep the
        // document's fixed columns under that check while the tax columns stay a computed set — one
        // insert either way, so the unique constraint on `order_id` still decides a concurrent second run.
        $invoice = new InvoiceRecord;

        $invoice->fill([
            'owner_type' => $order->owner_type,
            'owner_id' => $order->owner_id,
            'provider' => $order->provider,
            'order_id' => $order->getKey(),
            'number' => $this->number($issuedAt),
            'total_minor' => $order->total_minor,
            // The net, and ONLY where one was established. Null otherwise, for the same reason `tax_minor`
            // is null there: a subtotal equal to the total is not the absence of a claim, it is the claim
            // that the supply was untaxed. That is right under a small-business regime or an exempt supply
            // and wrong under a taxable one, and nobody determined which — so the document says nothing
            // rather than guessing, exactly as it already does about the tax.
            //
            // THE RENDERED DOCUMENT IS UNCHANGED, AND THAT WAS MEASURED BEFORE THIS WAS TOUCHED. Both
            // readers of the column derive the same figure from what is left: `InvoiceDocumentRenderer`
            // falls back to `total_minor - (tax_minor ?? 0)`, and `NormalizesInvoiceModel` — the one the
            // e-invoice goes through — sums the frozen LINES whenever there are any, which this issuer
            // always writes. So nothing a consumer sees moves; what stops is the record asserting a number
            // as established when it was assumed.
            //
            // The two tax readers never saw it either: `PeriodicTaxReturn` skips a sale that is not `oss`
            // with a destination country, and `InvoiceCrossBorderSalesCounter` selects on a non-empty
            // `destination_country`. Both columns are part of the basis block, so a document without a
            // basis is outside both — which is why dropping the net here cannot understate a filed return.
            'subtotal_minor' => $tax?->net->minorUnits,
            'currency' => $order->currency,
            'status' => InvoiceStatus::Paid,
            'issued_at' => $issuedAt,
            'lines' => $this->frozenLines($order),
        ]);

        $invoice->fill($this->taxAttributes($tax));
        $invoice->save();

        return $invoice;
    }

    /**
     * The tax basis for this cycle, or null where none could be established or the attempt failed.
     *
     * The catch is deliberate and narrow in its consequence: it costs the tax, never the document. See the
     * class docblock — the money is already collected by the time this runs.
     */
    private function determined(Order $order): ?DeterminedOrderTax
    {
        try {
            return $this->basis->for($order);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The tax columns a determined cycle freezes, or none at all.
     *
     * An empty array rather than a row of nulls, so a document with no basis is written exactly as it was
     * before this seam existed — the model's own defaults decide those columns, and a null spread over them
     * here would be a second place that says what they are.
     *
     * @return array<string, mixed>
     */
    private function taxAttributes(?DeterminedOrderTax $tax): array
    {
        if (! $tax instanceof DeterminedOrderTax) {
            return [];
        }

        $supply = $tax->characteristics;

        return [
            'tax_minor' => $tax->tax->minorUnits,
            'tax_rate_bps' => $tax->rateBps,
            'reverse_charge' => $tax->reverseCharge,
            'tax_exempt' => $tax->exempt,
            'oss' => $tax->oneStopShop,
            'tax_archetype' => $supply->archetype,
            'place_of_supply_rule' => $supply->placeOfSupply,
            'tax_rate_category' => $supply->rateCategory,
            'tax_exemption_reason' => $supply->exemptionReason,
            'destination_country' => $supply->destinationCountry,
            'destination_subdivision' => $supply->destinationSubdivision,
            // Both inclusive, and the end is a day earlier than the subscription's own period end — see
            // OrderTaxBasis::periodOf(), which is the one place that conversion is made.
            'service_period_start' => $tax->period->from,
            'service_period_end' => $tax->period->to,
        ];
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
