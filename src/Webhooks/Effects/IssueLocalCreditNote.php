<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Support\InvoiceNumberSequence;

/**
 * Issues the credit note for money refunded against a LOCALLY raised invoice.
 *
 * A provider that issues its own documents also announces the correction — Stripe sends
 * `credit_note.created`, and {@see PersistInvoiceCorrection} stores it. A local engine raised the invoice
 * itself, so nobody will announce anything: without this, a refunded cycle leaves the books overstating
 * turnover. The charge is recorded, the money going back is not, and the debit has no matching credit.
 *
 * ## It selects itself, rather than being wired per driver
 *
 * The effect acts only where the refunded payment leads to an invoice this package RAISED (one carrying an
 * `order_id`). A Stripe invoice has none, so the effect does nothing there and the provider's own
 * correction stands — no driver name appears anywhere in the decision.
 *
 * ## The amounts are POSITIVE, and that is not a detail
 *
 * A correcting document states what is being credited; its DOCUMENT TYPE inverts the accounting direction,
 * not a minus sign. EN 16931 is explicit about it, and {@see PersistInvoiceCorrection} already follows the
 * same rule for the provider path — a credit note with negative amounts would be a different document
 * saying something else, and the two paths would disagree about a customer's own books.
 *
 * ## Idempotency rides on the cumulative figure
 *
 * The refund event carries the CUMULATIVE amount refunded against that payment, so a redelivery repeats
 * the same value and converges to one row, while a second, larger refund produces a genuinely different
 * key. That is the same discipline the webhook backbone uses elsewhere and it is why no refund id is
 * needed: a provider that pings a bare resource id has none to give.
 *
 * ## What it will not do
 *
 * Credit more than the invoice states. A cumulative figure that exceeds the original — a provider bug, a
 * manual adjustment out of band, or an invoice reissued smaller — is capped rather than trusted. Credit
 * notes summing beyond their invoice is the one shape an auditor reads as fabricated.
 */
final readonly class IssueLocalCreditNote
{
    public function __construct(private InvoiceNumberSequence $numbers) {}

    public function __invoke(AddonRefunded $event): void
    {
        $invoice = $this->locallyRaisedInvoiceFor($event->paymentReference);

        if (! $invoice instanceof InvoiceRecord) {
            return;
        }

        // ONE transaction holding the invoice row, because the delta below is a read-modify-write over an
        // aggregate and the effect backbone does not serialize this for us. Two genuinely different refunds
        // against one payment arrive as two events with two different ids, so they get two different claims
        // and run in two parallel transactions: under READ COMMITTED neither sees the other's uncommitted
        // note, both read nothing credited yet, and both state their own cumulative figure. 7.50 then 20.00
        // against a 20.00 invoice is 27.50 again — the exact sum the delta exists to prevent, arriving
        // through concurrency instead of through arithmetic, and the differing keys cannot catch it.
        //
        // The INVOICE is the mutex, not the notes: the notes are what is being counted, and a lock over
        // rows that do not exist yet holds nothing back. The row's contents are not read for anything.
        //
        // Dedup on the payment reference would serialize them too, and would be wrong: a handled claim is
        // never re-claimed, so the second refund would be dropped rather than credited.
        //
        // `HandleWebhookEffect` already runs every effect inside a transaction, and nested this is a
        // savepoint — the OUTER transaction then owns the lock and releases it when the note commits, which
        // is exactly what is wanted. Written here so it holds when the effect is invoked directly too.
        DB::transaction(function () use ($event, $invoice): void {
            InvoiceRecord::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            $this->issueAgainst($event, $invoice);
        });
    }

    /** The decision and the document, both inside the caller's lock on the invoice. */
    private function issueAgainst(AddonRefunded $event, InvoiceRecord $invoice): void
    {
        // The event carries the provider's CUMULATIVE refunded total, so a second partial refund arrives as
        // a larger figure rather than as its own amount. A note for the cumulative figure is therefore the
        // second note stating the WHOLE refund again: 7.50 then 20.00 against a 20.00 invoice is 27.50 of
        // issued, numbered, immutable credit notes for 20.00 of refunded money.
        //
        // What each note states is the DELTA — what this refund added to what has already been credited.
        // The notes then sum to exactly what was refunded, which is the property an auditor reads them for,
        // and the class docblock names the alternative as the one shape that reads as fabricated.
        $cumulative = min($event->cumulativeRefunded->minorUnits, $invoice->total_minor);
        $credited = $cumulative - $this->alreadyCredited($invoice);

        if ($credited <= 0) {
            return;
        }

        // Keyed on the CUMULATIVE figure, not the delta. That is what a redelivery repeats — the same
        // cumulative total arriving twice is the same news — while two genuinely different refunds that
        // happen to add the same amount are different news and must each get their note.
        $key = [
            'provider' => $invoice->provider,
            'provider_id' => sprintf('%s:credited:%d', $event->paymentReference, $cumulative),
        ];

        // NO `exists()` PRE-CHECK, and its absence is the point. One stood here and became unreachable the
        // moment a note started stating the DELTA: a redelivery repeats a cumulative figure that has
        // already been credited, so `$credited` is zero and the guard above returns before this line. For
        // the pre-check to fire, a note keyed on a cumulative figure would have to exist while the notes
        // sum to LESS than it — which nothing can produce, because every note this writes adds exactly the
        // delta it was keyed on, and the invoice row is held while that sum is read.
        //
        // Nothing is lost by dropping it. The delta guard sits BEFORE the number is drawn, so a redelivery
        // still burns nothing from the sequence, and the unique index on (provider, provider_id) is the
        // backstop if the reasoning above is ever wrong: a duplicate raises rather than issues a second
        // numbered document. What is gained is that a branch no run can enter stops looking like a guard.
        $issuedAt = Carbon::now();

        InvoiceRecord::query()->create([
            ...$key,
            ...[
                'owner_type' => $invoice->owner_type,
                'owner_id' => $invoice->owner_id,
                'number' => $this->number($issuedAt),
                'credited_invoice_id' => $invoice->id,
                'credited_invoice_number' => $invoice->number,
                'total_minor' => $credited,
                'subtotal_minor' => $credited,
                'currency' => $event->cumulativeRefunded->currency,
                'status' => InvoiceStatus::Refunded,
                'issued_at' => $issuedAt,
                'buyer' => $invoice->buyer,
                'lines' => [[
                    'description' => sprintf('Refund of %s', $invoice->number ?? 'invoice'),
                    'quantity' => 1,
                    'unit_price_minor' => $credited,
                    'total_minor' => $credited,
                    'currency' => $event->cumulativeRefunded->currency,
                    'type' => 'credit',
                ]],
            ],
        ]);
    }

    /**
     * What this invoice has already been credited, across every note issued against it.
     *
     * Read from the notes themselves rather than tracked on the invoice: the notes ARE the record, an
     * issued one cannot change, and a counter beside them would be a second version of the same fact that
     * drifts the first time one is written by anything else.
     */
    private function alreadyCredited(InvoiceRecord $invoice): int
    {
        return (int) InvoiceRecord::query()
            ->where('credited_invoice_id', $invoice->id)
            ->sum('total_minor');
    }

    /**
     * The invoice this package raised for the order that payment settled, if there is one.
     *
     * Two hops rather than one, because a locally raised invoice is keyed to its ORDER while a refund
     * names a PAYMENT. Joining them is what tells a local document apart from a copied one.
     */
    private function locallyRaisedInvoiceFor(string $paymentReference): ?InvoiceRecord
    {
        $order = Order::query()->where('payment_reference', $paymentReference)->first();

        if (! $order instanceof Order) {
            return null;
        }

        return InvoiceRecord::query()
            ->where('order_id', $order->getKey())
            ->whereNull('credited_invoice_id')
            ->first();
    }

    /** A credit note carries its own number from the same sequence, so the series stays gapless. */
    private function number(Carbon $issuedAt): string
    {
        $year = $issuedAt->format('Y');

        return sprintf('CN-%s-%07d', $year, $this->numbers->next("credit_note:{$year}"));
    }
}
