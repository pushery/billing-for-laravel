<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Concerns;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Exceptions\InvalidInvoiceCorrection;
use Pushery\Billing\Invoicing\EnInvoiceTaxCategory;
use Pushery\Billing\Invoicing\Line;
use Pushery\Billing\Invoicing\MarginDocumentGuard;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\EnInvoiceTaxTreatment;

/**
 * The syntax-agnostic invoice model shared by every EN 16931 writer: the seller and buyer parties, the
 * line items, and the per-rate tax breakdown. UBL (XRechnung) and CII (ZUGFeRD) are two syntaxes over
 * the SAME semantic model, so this normalization lives once — a tax band computed one way for XRechnung
 * and another for ZUGFeRD would be a silent conformance drift between the two outputs of one invoice.
 *
 * The consuming class must expose a {@see SellerPartyResolver} through `sellerPartyResolver()`; its default
 * names the platform, so a single-seller document is unchanged.
 */
trait NormalizesInvoiceModel
{
    abstract private function sellerPartyResolver(): SellerPartyResolver;

    /**
     * The seller named on this document.
     *
     * Read from the frozen per-document snapshot when there is one, so a document keeps naming whoever it
     * named when it was issued even after the config or the merchant changes. A row written before the
     * snapshot column existed has none and falls back to the resolver — whose default is the platform
     * company, byte-identical to what these writers produced before the seller was resolvable.
     */
    private function seller(InvoiceRecord $invoice): Party
    {
        $snapshot = $invoice->getAttribute('seller');

        if (is_array($snapshot)) {
            return Party::fromArray($snapshot);
        }

        return $this->sellerPartyResolver()->sellerFor($invoice);
    }

    private function buyer(InvoiceRecord $invoice): Party
    {
        $buyer = $invoice->getAttribute('buyer');

        return Party::fromArray(is_array($buyer) ? $buyer : []);
    }

    /**
     * The EN 16931 document type code (BT-3), shared by both writers so the two syntaxes never disagree.
     *
     * Four codes, selected by the document's ROLE rather than by a boolean. A cancellation is 381 and an
     * amendment is 384 — two different documents a tax authority reads apart by this code alone, which is
     * exactly why one boolean could never carry both. A self-billed invoice is 389, a document treated
     * differently from the ordinary 380. Correction wins first: correcting a self-billed invoice produces a
     * correction, not another self-bill. In every case the code, not a negative amount, carries the
     * correcting meaning, so the amounts stay positive.
     *
     * A row whose kind was never recorded is a cancellation, which is what every correction written before
     * the two roles existed is — so the code such a row renders is the code it always rendered.
     *
     * An amendment MUST name what it amends (BR-55), and the check sits here rather than only on the model
     * that creates one: a row that reached the database without a reference — an older row, an import, a
     * direct write — would otherwise be serialized into a document that is invalid the moment it is read,
     * and the reader is a tax authority. Refusing to write it is the only honest outcome.
     */
    private function typeCode(InvoiceRecord $invoice): string
    {
        if (! $invoice->isCorrection()) {
            return $invoice->isSelfBilled() ? '389' : '380';
        }

        if ($invoice->correction_kind !== InvoiceCorrectionKind::Amendment) {
            return '381';
        }

        $origin = $invoice->credited_invoice_number;

        if ($origin === null || $origin === '') {
            throw InvalidInvoiceCorrection::amendmentWithoutReference();
        }

        return '384';
    }

    /**
     * The buyer's BT-10 reference (the Leitweg-ID for a German B2G supply), from the invoice's own
     * `buyer_reference` column. The column is authoritative — it is the frozen value the invoice was issued
     * with — and the buyer snapshot's `reference` is only a fallback for a row written before the column
     * existed. Null when neither carries one.
     */
    private function buyerReference(InvoiceRecord $invoice): ?string
    {
        $column = $invoice->buyer_reference;

        if (is_string($column) && $column !== '') {
            return $column;
        }

        $buyer = $invoice->getAttribute('buyer');
        $reference = is_array($buyer) ? ($buyer['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * The BT-120 tax exemption / reverse-charge reason text.
     *
     * Three sources, in falling order of authority, and the order is the whole design.
     *
     * A stored `vat_note` wins: it is a sentence somebody wrote down on purpose (an OSS reference, a
     * specific clause), and it is frozen with the document.
     *
     * Otherwise the wording is DERIVED from the frozen `tax_exemption_reason` and the current locale. This
     * is the half that was missing, and its absence was expensive: nothing wrote `vat_note`, so BT-120 fell
     * straight through to the category's generic English fallback — `Tax exempt` on a small-business
     * settlement, naming no statute at all, on a document the platform raises in the creator's name and on a
     * column no consumer can heal afterwards.
     *
     * Deriving the WORDING from a frozen REASON is what keeps this honest. The legal fact is on the row and
     * cannot move; only its rendering is computed, which is what makes it translatable — a frozen free-text
     * string could never be. Deriving the fact itself would be the opposite move and would be wrong.
     *
     * A margin-taxed document is the one exception to the second source. It names its scheme rather than an
     * exemption, and in the wording the jurisdiction profile prescribes, because paraphrased prescribed
     * wording is not the prescribed wording.
     *
     * Null when the supply carries no exemption at all, and null matters: a standard-rated band must carry
     * no reason (BR-S-*), so returning a sentence here would produce a document a validator rejects.
     */
    private function vatNote(InvoiceRecord $invoice): ?string
    {
        $reverseCharge = (bool) $invoice->reverse_charge;

        $note = $invoice->vat_note;

        if (is_string($note) && $note !== '') {
            return $note;
        }

        // Where the profile supplies no wording, null lets the category's own description stand in, which at
        // least names the scheme. The same key the PDF half prints, so the two halves cannot say different things.
        if ($invoice->taxation_basis instanceof TaxationBasis && $invoice->taxation_basis->taxesMarginOnly()) {
            $key = Container::getInstance()->make(MarginDocumentGuard::class)->wordingKey($invoice->taxation_basis);
            $wording = $key === null ? null : Lang::get($key);

            return is_string($wording) ? $wording : null;
        }

        $reason = $invoice->tax_exemption_reason ?? ($reverseCharge ? TaxExemptionReason::ReverseCharge : null);

        // Only the grounds whose statutory sentence this package actually ships are named here. The others
        // fall through to the category's own wording rather than to an invented one — an exemption named
        // wrongly is worse than one named generically, because it claims a specific relief nobody asserted.
        //
        // Each key is written out WHOLE rather than assembled from a suffix, and that is not style. The
        // guard that proves every shipped translation is reachable greps for literal keys; built from a
        // variable, these two were invisible to it — and a guard blind to a key it should see would also be
        // blind to one that had genuinely died. It said so, correctly, the first time this was written.
        $note = match ($reason) {
            TaxExemptionReason::ReverseCharge => Lang::get('billing::invoice.reverse_charge_note'),
            TaxExemptionReason::DomesticSmallBusiness => Lang::get('billing::invoice.small_business_note'),
            TaxExemptionReason::UnionSmallBusinessScheme => Lang::get('billing::invoice.union_small_business_note'),
            TaxExemptionReason::NotConsideration => Lang::get('billing::invoice.not_consideration_note'),
            default => null,
        };

        return is_string($note) ? $note : null;
    }

    /**
     * How this document is taxed — the derivation that used to stand byte-identically in both writers.
     *
     * Eight places read these same five lines. Correct the rule in one renderer and the other keeps the old
     * reading, and the defect is then format-specific: it shows up in whichever of the two nobody is looking
     * at. One derivation cannot disagree with itself.
     */
    private function taxTreatmentFor(InvoiceRecord $invoice): EnInvoiceTaxTreatment
    {
        $reverseCharge = (bool) $invoice->reverse_charge;
        $lines = $this->lines($invoice);
        $bands = $this->chargedTaxIn($this->taxBandsFor($lines, $invoice), $invoice);

        // Derive the document net + tax from the lines so BT-110 equals the sum of the per-band tax
        // (BR-CO-14) and the totals stay internally consistent (BR-CO-13/15). A lineless invoice cannot
        // carry a breakdown, so it falls back to the stored figures.
        $net = $lines === [] ? ($invoice->subtotal_minor ?? $invoice->total_minor) : $this->sum($lines, fn (Line $line): int => $line->netMinor);

        // A reverse charge shifts the VAT to the buyer: the seller charges zero. The document tax and every
        // AE band's tax must be zero (BR-AE-*), or the AE category would carry VAT and the payable would
        // overstate the net — so force zero here rather than trust a line's notional rate.
        $tax = $reverseCharge ? 0 : ($lines === [] ? ($invoice->tax_minor ?? 0) : $this->sum($bands, fn (array $band): int => $band['tax']));

        return new EnInvoiceTaxTreatment(
            invoice: $invoice,
            lines: $lines,
            bands: $bands,
            net: $net,
            tax: $tax,
            reverseCharge: $reverseCharge,
            exempt: (bool) $invoice->tax_exempt,
            // BT-120: the exemption reason text, DERIVED from the invoice's vat_note column (a reverse
            // charge with no stored note falls back to the standard wording), never a hardcoded literal.
            exemptionReason: $this->vatNote($invoice),
        );
    }

    /**
     * Whether the document is settled: an invoice paid, or a credit note whose money went back.
     *
     * A settled document states its whole amount as already paid (BT-113), so the amount due (BT-115) is zero and
     * EN 16931 asks for neither a due date nor payment terms (BR-CO-25). Read from the status rather than from
     * the money, because the status is what the document records about its own settlement.
     */
    private function settled(InvoiceRecord $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true);
    }

    /**
     * When an open document is due (BT-9): the due date it records, or the day it was issued where it records
     * none. BR-CO-25 asks for a due date or payment terms wherever an amount is due, and a document that names
     * no later date is due when it is issued.
     */
    private function dueDateOf(InvoiceRecord $invoice): string
    {
        return ($invoice->due_at ?? $invoice->issued_at ?? Carbon::now())->format('Y-m-d');
    }

    /**
     * The bands carry the tax the document charged, where that is their own rounding a cent away each.
     *
     * A price set gross has no net for some amounts that gives it back at the statutory rate: 9.99 at 19 % is
     * 8.39 and 1.60, while 8.39 at 19 % rounds to 1.59. A source that rounds each line's tax and sums them lands
     * the same way: three lines of 0.35 at 19 % charge 0.21, and their band rounds to 0.20. Recomputed, the bands
     * state a tax the customer was not charged and the document pays less or more than it collected. EN 16931 lets
     * a band's tax sit within a unit of its base times its rate (BR-CO-17), so the charged figure stands, one cent
     * at a time, on the taxed bands whose own rounding moved furthest the other way. Further apart than a cent per
     * taxed band, the two disagree about more than rounding, and the bands keep what their lines say.
     *
     * A band without a rate, of amounts collected on behalf of another party, and a band at zero carry no tax and
     * take no cent: the fee stated beside the goods it passed through is still the one band the charged tax is on.
     *
     * @param  list<array{rate: ?float, taxable: int, tax: int}>  $bands
     * @return list<array{rate: ?float, taxable: int, tax: int}>
     */
    private function chargedTaxIn(array $bands, InvoiceRecord $invoice): array
    {
        $charged = $invoice->tax_minor;
        $taxed = array_keys(array_filter($bands, static fn (array $band): bool => $band['rate'] !== null && $band['rate'] > 0));

        if ($taxed === [] || $charged === null || (bool) $invoice->reverse_charge) {
            return $bands;
        }

        $difference = $charged - $this->sum($bands, fn (array $band): int => $band['tax']);

        if ($difference === 0 || abs($difference) > count($taxed)) {
            return $bands;
        }

        // How far each band's own rounding moved from its exact tax. A band rounded down has the first claim on a cent
        // more, a band rounded up on a cent less.
        $residual = static fn (int $index): float => $bands[$index]['taxable'] * (float) $bands[$index]['rate'] / 100 - $bands[$index]['tax'];
        usort($taxed, static fn (int $a, int $b): int => $difference > 0 ? $residual($b) <=> $residual($a) : $residual($a) <=> $residual($b));

        $cents = array_fill_keys(array_slice($taxed, 0, abs($difference)), $difference > 0 ? 1 : -1);
        $out = [];

        foreach ($bands as $index => $band) {
            $out[] = ['rate' => $band['rate'], 'taxable' => $band['taxable'], 'tax' => $band['tax'] + ($cents[$index] ?? 0)];
        }

        return $out;
    }

    /** @return list<Line> */
    private function lines(InvoiceRecord $invoice): array
    {
        $lines = $invoice->getAttribute('lines');
        $out = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line)) {
                $out[] = Line::fromArray($line);
            }
        }

        return $out;
    }

    /**
     * The per-rate tax bands to render, reverse-charge aware. A normal supply groups line net by rate; a
     * reverse charge is a SINGLE AE @ 0% band over the whole taxable base — lines at distinct NOTIONAL rates
     * must not emit multiple zero-rated category groups, which is a non-conformant EN 16931 breakdown.
     *
     * A one-stop-shop supply is banded at the rate the SALE was declared under, read from the invoice's own
     * frozen column rather than from the lines. The lines carry a rate derived at pricing time from a product
     * that can be reclassified afterwards; the column is what the return was filed on. Re-rendering a
     * document from the lines could therefore state a rate the platform never declared for that sale, into a
     * country it never declared it to — and the document would still add up.
     *
     * An amount collected on behalf of another party is not the issuer's supply, so it joins none of those bands. It
     * is stated after them in a band of its own, with no rate and no tax, and a document without one has none.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: ?float, taxable: int, tax: int}>
     */
    private function taxBandsFor(array $lines, ?InvoiceRecord $invoice = null): array
    {
        [$supplied, $collected] = $this->collectedApart($lines, $invoice);

        if (! $invoice instanceof InvoiceRecord || ! (bool) $invoice->reverse_charge) {
            $declared = $this->declaredOssRate($invoice);

            return [...($declared === null ? $this->taxBands($supplied) : $this->singleBand($supplied, $declared)), ...$collected];
        }

        return [...$this->singleBand($supplied, 0.0), ...$collected];
    }

    /**
     * The lines that are the issuer's supply, and the band of the amounts it collected on behalf of another party.
     *
     * Apart only where the document can carry a band of its own for them. On a document that is exempt or outside the
     * scope as a whole they stay among the supply and join its zero band, because that category admits one band. A
     * document with no such amount is banded from all its lines, and its category is not asked here.
     *
     * @param  list<Line>  $lines
     * @return array{list<Line>, list<array{rate: ?float, taxable: int, tax: int}>}
     */
    private function collectedApart(array $lines, ?InvoiceRecord $invoice): array
    {
        $supplied = array_values(array_filter($lines, static fn (Line $line): bool => ! $line->collectedOnBehalf()));

        if (count($supplied) === count($lines)) {
            return [$lines, []];
        }

        if ($invoice instanceof InvoiceRecord && ! EnInvoiceTaxCategory::forDocument($invoice, null)->isCollectedOnBehalf()) {
            return [$lines, []];
        }

        $collected = $this->sum($lines, fn (Line $line): int => $line->collectedOnBehalf() ? $line->netMinor : 0);

        return [$supplied, [['rate' => null, 'taxable' => $collected, 'tax' => 0]]];
    }

    /**
     * The rate a one-stop-shop supply was declared at, or null when the invoice is not one.
     *
     * Both the flag and the rate are required: a document flagged as such but carrying no rate cannot say
     * what it was declared at, and falling back to the lines there would silently produce the very
     * re-derivation this exists to prevent.
     */
    private function declaredOssRate(?InvoiceRecord $invoice): ?float
    {
        if (! $invoice instanceof InvoiceRecord || ! (bool) $invoice->oss) {
            return null;
        }

        $rate = $invoice->oss_rate;

        return is_numeric($rate) ? (float) $rate : null;
    }

    /**
     * One band over the whole taxable base at a single rate.
     *
     * The tax is the DIFFERENCE from the invoice's own total elsewhere; here it is the rate applied to the
     * base, which is what a breakdown states. An empty base emits no band at all — a zero-value band is not
     * a statement EN 16931 has a shape for.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function singleBand(array $lines, float $rate): array
    {
        $taxable = $this->sum($lines, fn (Line $line): int => $line->netMinor);

        if ($taxable === 0) {
            return [];
        }

        return [['rate' => $rate, 'taxable' => $taxable, 'tax' => (int) round($taxable * $rate / 100)]];
    }

    /**
     * Group line net by tax rate and compute the tax per band.
     *
     * @param  list<Line>  $lines
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function taxBands(array $lines): array
    {
        $taxable = [];
        $rates = [];

        foreach ($lines as $line) {
            // A line without a rate reaches this only on a document that is exempt or outside the scope as a whole,
            // where it belongs to the document's zero band.
            $rate = $line->taxRate ?? 0.0;
            $key = $this->rate($rate);
            $taxable[$key] = ($taxable[$key] ?? 0) + $line->netMinor;
            $rates[$key] = $rate;
        }

        $bands = [];

        foreach ($taxable as $key => $sum) {
            $rate = $rates[$key];
            $bands[] = ['rate' => $rate, 'taxable' => $sum, 'tax' => (int) round($sum * $rate / 100)];
        }

        return $bands;
    }

    /**
     * Sum an integer projection over a list.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): int  $value
     */
    private function sum(array $items, callable $value): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += $value($item);
        }

        return $total;
    }

    /** A tax rate as a plain percentage string, e.g. 19.0 → "19", 25.5 → "25.5". */
    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    /**
     * The note a self-billed document has to carry, or null where it is not one.
     *
     * A document the buyer wrote about the seller's own supply is only a valid invoice if it SAYS so. Read
     * without the statement it looks like an ordinary invoice issued by the wrong party, and the recipient
     * has no way to tell that it was written under an arrangement they agreed to. The wording is a
     * jurisdiction's, so it comes from the translations rather than from here.
     */
    private function selfBillingNote(InvoiceRecord $invoice): ?string
    {
        return $this->typeCode($invoice) === '389'
            ? (string) Lang::get('billing::invoice.self_billed_note')
            : null;
    }

    /**
     * A text as XML 1.0 can carry it: valid UTF-8, without a character the format has no place for.
     *
     * Names and addresses are written as they were entered or configured, and XML 1.0 holds neither a control
     * character nor bytes that are not UTF-8. Given one, the document came out wrong without an error: a control
     * character turned into a replacement character, and invalid UTF-8 failed the serialization, so the writer
     * returned an empty string as the e-invoice. Invalid bytes are replaced the way mbstring replaces them, and
     * a character XML 1.0 excludes is left out. Tab, line feed and carriage return stay.
     */
    private function xmlText(string $text): string
    {
        $valid = mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $valid);
    }
}
