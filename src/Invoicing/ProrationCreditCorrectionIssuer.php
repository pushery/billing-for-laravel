<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Marketplace\SettlementCorrectionIssuer;
use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\CreditConsumption;

/**
 * The correcting document a redeemed proration credit owes the invoice it came out of.
 *
 * ## The tax question, decided
 *
 * A proration credit hands back part of the consideration for a period the customer paid for and did not
 * use. Nothing is refunded — the money stays here as spendable balance — so the reduction becomes real
 * only when that balance is actually spent. At that moment the consideration for the ORIGINAL supply has
 * been reduced, and § 17 Abs. 2 Nr. 2 UStG says the tax base of that supply is corrected: the old invoice,
 * not the new one the balance was set against.
 *
 * ## Why a DOCUMENT rather than a booking row
 *
 * The export could in principle emit a Haben line and be done. It must not, for two reasons that are not
 * about convenience. A correction that exists only as a figure in a ledger corrects nothing anybody can be
 * shown — the same sentence {@see SettlementCorrectionIssuer} opens with. And
 * the RATE lives in the revenue account the row would name, which is resolved from the original invoice's
 * frozen tax characteristics; reaching for those from inside the export would put a document query in a
 * path that deliberately has none.
 *
 * As a document it costs the export nothing at all: the period batch reads every invoice of its window,
 * the marker flips to Haben because this row is a correction with a positive total, and the revenue
 * account resolves from the characteristics copied below.
 *
 * ## It is the first correction this package raises on its OWN decision — and that is argued, not assumed
 *
 * Every other correction here hangs on an outside fact: a provider webhook, an admin action, a consumer
 * call. The prepaid-term cancellation service states the reason it is a service
 * rather than a listener — the package cannot tell which cancellations owe anything back, and has no good
 * idempotency key for the ones that do.
 *
 * Neither holds here. What is owed is not a judgement: the FIFO replay names exactly which lots a spend
 * consumed and in what amounts. And the key is already in the schema — a credit ledger entry is
 * append-only, so its id identifies this redemption for as long as the books exist.
 *
 * ## What it refuses to do
 *
 * Correct a lot whose source invoice it cannot name. A proration credit carries one only when the reading
 * that produced it could point at exactly ONE paid invoice for the period, so the absence is a real state
 * rather than a defect. Guessing an invoice would put a tax correction against a document that did not
 * carry the supply, and guessing a rate would book it at the wrong one. The share stays reported instead —
 * {@see DatevPeriodBatch} states it — which is this package's standing answer: half-booked is worse than
 * unbooked.
 */
final readonly class ProrationCreditCorrectionIssuer
{
    public function __construct(private CreditNoteNumber $numbers) {}

    /**
     * Issue what this offset owes — one document per invoice a consumed proration credit came out of.
     *
     * The history is read here rather than handed in, and the read is the WHOLE balance rather than a
     * period: a credit granted in March and spent in June is the ordinary case, and a replay that started
     * at the window would report this spend as having consumed nothing. One statement per redemption is
     * the right price — a redemption is a deliberate event, not a page render.
     *
     * @return list<InvoiceRecord> the documents raised, empty when the offset consumed nothing unpaid
     */
    public function issueFor(CreditLedgerEntry $offset): array
    {
        $history = CreditLedgerEntry::query()
            ->where('owner_type', $offset->owner_type)
            ->where('owner_id', $offset->owner_id)
            ->where('currency', $offset->currency)
            ->orderBy('id')
            ->get()
            ->all();

        $issued = [];

        foreach ($this->owedPerInvoice($offset, array_values($history)) as $invoiceId => $minor) {
            $original = InvoiceRecord::query()->whereKey($invoiceId)->first();

            if (! $original instanceof InvoiceRecord) {
                // The invoice the credit named is gone. Same answer as a credit with no source at all: the
                // share is reported rather than corrected against a document nobody can produce.
                continue;
            }

            $correction = $this->correct($offset, $original, $minor);

            if ($correction instanceof InvoiceRecord) {
                $issued[] = $correction;
            }
        }

        return $issued;
    }

    /**
     * How much this offset owes each source invoice, keyed by invoice id.
     *
     * Summed per invoice rather than emitted per lot: two proration credits from the same invoice are two
     * ledger entries and one supply, and two correcting documents against one invoice for one redemption
     * would read as two separate reductions to anybody adding them up.
     *
     * @param  list<CreditLedgerEntry>  $history
     * @return array<int|string, int>
     */
    private function owedPerInvoice(CreditLedgerEntry $offset, array $history): array
    {
        $owed = [];
        $invoiceClass = new InvoiceRecord()->getMorphClass();

        foreach (CreditConsumption::of($offset, $history) as $lot) {
            if ($lot->reason->booksAgainstMoney()) {
                // Paid credit. The redemption releases the liability it opened and corrects nothing: the
                // original sale is unchanged, somebody simply used what they had bought.
                continue;
            }

            $source = $lot->source;

            if ($source === null || $source->type !== $invoiceClass) {
                continue;
            }

            $owed[$source->id] = ($owed[$source->id] ?? 0) + $lot->amount->minorUnits;
        }

        return $owed;
    }

    /** One correcting document, or none when this offset already has one against this invoice. */
    private function correct(CreditLedgerEntry $offset, InvoiceRecord $original, int $minor): ?InvoiceRecord
    {
        // Keyed on the ledger entry and the invoice, which is the pair that identifies this correction: one
        // redemption can reduce two invoices, and the same invoice can be reduced again by a later one.
        $key = [
            'provider' => $original->provider,
            'provider_id' => sprintf('credit-offset:%d:%d', $offset->id, $original->id),
        ];

        // Asked BEFORE the number is drawn, so a repeated call burns nothing from the series.
        //
        // ON `provider_id` ALONE, and not on the pair. An invoice raised outside an order carries no
        // provider, and `where(['provider' => null, …])` compiles to `provider = NULL` — never true in SQL,
        // so the check would silently stop firing on exactly those invoices and a second call would issue a
        // second numbered document. The value here embeds the ledger entry id, which is unique on its own;
        // the index on the pair stays the backstop wherever a provider IS set.
        if (InvoiceRecord::query()->where('provider_id', $key['provider_id'])->exists()) {
            return null;
        }

        $issuedAt = Carbon::now();

        return InvoiceRecord::query()->create([
            ...$key,
            ...[
                'owner_type' => $original->owner_type,
                'owner_id' => $original->owner_id,
                'number' => $this->numbers->next($issuedAt),
                'credited_invoice_id' => $original->id,
                'credited_invoice_number' => $original->number,
                // An AMENDMENT, never a cancellation. A null here renders as EN 16931 type 381, which says
                // the original is void in full — and this reduces part of a supply that still stands.
                'correction_kind' => InvoiceCorrectionKind::Amendment,
                // Positive, because the document type carries the direction and a minus sign would state
                // something else. The export's marker reads `isCorrection()` against this sign.
                'total_minor' => $minor,
                'subtotal_minor' => $minor,
                'currency' => $original->currency,
                // The FROZEN characteristics of the supply being reduced, copied rather than re-derived.
                // They are what the revenue account is resolved from, and a correction shares the tax
                // position of the supply it corrects — determining it again here would be a second opinion
                // about a question that was settled when the original was issued.
                'tax_rate_bps' => $original->tax_rate_bps,
                'oss' => $original->oss,
                'destination_country' => $original->destination_country,
                'status' => InvoiceStatus::Refunded,
                'issued_at' => $issuedAt,
                'buyer' => $original->buyer,
                'lines' => [[
                    'description' => sprintf('Reduction of consideration on %s', $original->number ?? 'invoice'),
                    'quantity' => 1,
                    'unit_price_minor' => $minor,
                    'total_minor' => $minor,
                    'currency' => $original->currency,
                    'type' => 'credit',
                ]],
            ],
        ]);
    }
}
