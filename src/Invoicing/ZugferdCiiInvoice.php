<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\EInvoice;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Invoicing\Concerns\NormalizesInvoiceModel;
use Pushery\Billing\Marketplace\ConfigSellerPartyResolver;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * A dependency-free EN 16931 writer in UN/CEFACT CII syntax — the XML ZUGFeRD/Factur-X embed in a
 * PDF/A-3. It is the CII twin of {@see XRechnungInvoice} (which emits the same EN 16931 model in UBL
 * syntax for standalone XRechnung); both share {@see NormalizesInvoiceModel} so the seller, buyer, lines
 * and tax bands are computed once and can never drift between the two syntaxes.
 *
 * CII is strictly sequence-ordered (unlike the looser UBL): every element must appear in the exact XSD
 * order, so the helpers below build each aggregate in that order. The EN 16931 guideline id marks the
 * profile; amounts are positive and a credit note carries type code 381 (not a negative total), exactly
 * as in the UBL writer.
 */
final readonly class ZugferdCiiInvoice implements EInvoice
{
    use NormalizesInvoiceModel;

    private const string RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

    private const string RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    private const string UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /** The EN 16931 core guideline: the profile a conformant CII invoice claims (BT-24). */
    private const string GUIDELINE = 'urn:cen.eu:en16931:2017';

    private SellerPartyResolver $sellerResolver;

    public function __construct(
        Repository $config,
        ?SellerPartyResolver $sellerResolver = null,
    ) {
        // Optional and last, so `new ZugferdCiiInvoice($config)` still constructs. It defaults to the platform
        // company — the single-seller answer — so nothing about the rendered output changes without a binding.
        // Config is only needed to build that default resolver; the writer keeps no config of its own.
        $this->sellerResolver = $sellerResolver ?? new ConfigSellerPartyResolver($config);
    }

    public function render(InvoiceRecord $invoice): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT);
        $doc->appendChild($root);

        $currency = $invoice->currency;
        $reference = $invoice->number ?? (string) $invoice->id;
        $reverseCharge = (bool) $invoice->reverse_charge;
        $exempt = (bool) $invoice->tax_exempt;

        $root->appendChild($this->documentContext($doc));
        $root->appendChild($this->document($doc, $reference, $this->typeCode($invoice), $invoice->issued_at ?? Carbon::now(), $this->selfBillingNote($invoice)));

        $lines = $this->lines($invoice);
        $bands = $this->taxBandsFor($lines, $reverseCharge, $invoice);

        // Derive the document net + tax from the lines so the header totals equal the sum of the per-band
        // figures (BR-CO-13/14/15); a lineless invoice falls back to the stored figures.
        $net = $lines === [] ? ($invoice->subtotal_minor ?? $invoice->total_minor) : $this->sum($lines, fn (Line $line): int => $line->netMinor);
        // A reverse charge shifts the VAT to the buyer: the seller charges zero. The per-band CalculatedAmount
        // is already forced to zero (headerTax), so the document tax MUST be zero too — otherwise BT-110 would
        // not equal the sum of the zero bands (BR-CO-14) and the payable would overstate the true net.
        $tax = $reverseCharge ? 0 : ($lines === [] ? ($invoice->tax_minor ?? 0) : $this->sum($bands, fn (array $band): int => $band['tax']));

        $transaction = $doc->createElement('rsm:SupplyChainTradeTransaction');

        foreach ($lines as $index => $line) {
            $transaction->appendChild($this->line($doc, $index + 1, $line, $currency, $reverseCharge, $exempt, $invoice));
        }

        $transaction->appendChild($this->headerAgreement($doc, $invoice, $reference));
        $transaction->appendChild($this->headerDelivery($doc, $invoice));
        $transaction->appendChild($this->headerSettlement($doc, $bands, $net, $tax, $currency, $reverseCharge, $exempt, $invoice));

        $root->appendChild($transaction);

        return (string) $doc->saveXML();
    }

    private function documentContext(DOMDocument $doc): DOMElement
    {
        $context = $doc->createElement('rsm:ExchangedDocumentContext');
        $parameter = $doc->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
        $this->el($doc, $parameter, 'ram:ID', self::GUIDELINE);
        $context->appendChild($parameter);

        return $context;
    }

    /**
     * `CarbonInterface` rather than `Carbon`, and that is a fix rather than a widening for its own sake.
     *
     * The parameter only ever gets formatted. Narrowing it to the MUTABLE Carbon made this renderer reject a
     * document it had just been handed: a model straight out of `create()` returns the value it was given,
     * not the cast one, so an issuer that works in `CarbonImmutable` produced a document whose `issued_at`
     * was immutable — and rendering it raised a TypeError. Only a document read back from the database
     * worked, which is why every test here passed: they all build their rows and re-read them.
     *
     * The UBL writer never had the problem because it formats the value where it stands.
     */
    private function document(DOMDocument $doc, string $reference, string $typeCode, CarbonInterface $issuedAt, ?string $note = null): DOMElement
    {
        $document = $doc->createElement('rsm:ExchangedDocument');
        $this->el($doc, $document, 'ram:ID', $reference);
        // BT-3 type code: 380 invoice, 381 cancellation, 384 amendment, 389 self-billed invoice — derived
        // once in the shared trait. The code carries the correcting meaning, so amounts stay positive — a
        // negative total would be non-conformant.
        //
        // All FOUR are named on purpose; the sibling writer carries the same sentence and the same reason.
        // Listing three reads as the complete table, and the one left out was 384 — the code that separates
        // an amendment from a cancellation for the authority reading the document.
        $this->el($doc, $document, 'ram:TypeCode', $typeCode);

        $issue = $doc->createElement('ram:IssueDateTime');
        // Qualifier 102 = CCYYMMDD, the only date format EN 16931 allows for the issue date (BT-2).
        $date = $this->el($doc, $issue, 'udt:DateTimeString', $issuedAt->format('Ymd'));
        $date->setAttribute('format', '102');
        $document->appendChild($issue);

        // BT-22: a self-billed document has to SAY it is one. Without the statement it reads as an ordinary
        // invoice issued by the wrong party, and the recipient cannot tell it was written under an
        // arrangement they agreed to. CII puts the note after the issue date; it is absent on everything
        // else, which is what keeps every other document byte-identical.
        if ($note !== null) {
            $included = $doc->createElement('ram:IncludedNote');
            $this->el($doc, $included, 'ram:Content', $note);
            $document->appendChild($included);
        }

        return $document;
    }

    /** One line item (BG-25): product, net unit price, billed quantity, the line's VAT category and net. */
    private function line(DOMDocument $doc, int $number, Line $line, string $currency, bool $reverseCharge, bool $exempt, InvoiceRecord $invoice): DOMElement
    {
        $item = $doc->createElement('ram:IncludedSupplyChainTradeLineItem');

        $lineDocument = $doc->createElement('ram:AssociatedDocumentLineDocument');
        $this->el($doc, $lineDocument, 'ram:LineID', (string) $number);
        $item->appendChild($lineDocument);

        $product = $doc->createElement('ram:SpecifiedTradeProduct');
        $this->el($doc, $product, 'ram:Name', $line->description);
        $item->appendChild($product);

        $agreement = $doc->createElement('ram:SpecifiedLineTradeAgreement');
        $price = $doc->createElement('ram:NetPriceProductTradePrice');
        $this->amount($doc, $price, 'ram:ChargeAmount', $line->unitPriceMinor, $currency);
        $agreement->appendChild($price);
        $item->appendChild($agreement);

        $delivery = $doc->createElement('ram:SpecifiedLineTradeDelivery');
        $quantity = $this->el($doc, $delivery, 'ram:BilledQuantity', $line->quantity);
        $quantity->setAttribute('unitCode', $line->unit);
        $item->appendChild($delivery);

        $settlement = $doc->createElement('ram:SpecifiedLineTradeSettlement');
        $settlement->appendChild($this->lineTax($doc, $line->taxRate, $reverseCharge, $exempt, $invoice));

        // BG-14 the period this line covers (BT-73 start, BT-74 end). Written only when the line states one,
        // so a document without periods is byte-for-byte what it always was. CII orders the billing period
        // after the line's tax and before its monetary summation.
        if ($line->hasPeriod()) {
            $period = $doc->createElement('ram:BillingSpecifiedPeriod');
            $this->periodDate($doc, $period, 'ram:StartDateTime', (string) $line->periodStart);
            $this->periodDate($doc, $period, 'ram:EndDateTime', (string) $line->periodEnd);
            $settlement->appendChild($period);
        }

        $summation = $doc->createElement('ram:SpecifiedTradeSettlementLineMonetarySummation');
        $this->amount($doc, $summation, 'ram:LineTotalAmount', $line->netMinor, $currency);
        $settlement->appendChild($summation);
        $item->appendChild($settlement);

        return $item;
    }

    /**
     * One end of a billing period, in the qualified form CII requires.
     *
     * Qualifier 102 = CCYYMMDD, the same one the issue date uses. A period stated in any other format is not
     * a date to a reader of this syntax, and the document would fail validation on a field that looks right.
     */
    private function periodDate(DOMDocument $doc, DOMElement $parent, string $name, string $date): void
    {
        $node = $doc->createElement($name);
        $value = $this->el($doc, $node, 'udt:DateTimeString', str_replace('-', '', $date));
        $value->setAttribute('format', '102');
        $parent->appendChild($node);
    }

    /** The line-level VAT category (BT-151/152): code + rate only; the exemption reason lives on the header band. */
    private function lineTax(DOMDocument $doc, float $rate, bool $reverseCharge, bool $exempt, InvoiceRecord $invoice): DOMElement
    {
        $tax = $doc->createElement('ram:ApplicableTradeTax');
        $this->el($doc, $tax, 'ram:TypeCode', 'VAT');
        $this->el($doc, $tax, 'ram:CategoryCode', $this->categoryFor($invoice, $rate, $reverseCharge, $exempt)->code);
        $this->el($doc, $tax, 'ram:RateApplicablePercent', $this->rate($reverseCharge || $exempt ? 0.0 : $rate));

        return $tax;
    }

    private function headerAgreement(DOMDocument $doc, InvoiceRecord $invoice, string $reference): DOMElement
    {
        $agreement = $doc->createElement('ram:ApplicableHeaderTradeAgreement');
        // BT-10 buyer reference: the Leitweg-ID for B2G, the invoice reference for B2B.
        $this->el($doc, $agreement, 'ram:BuyerReference', $this->buyerReference($invoice) ?? $reference);
        $agreement->appendChild($this->party($doc, 'ram:SellerTradeParty', $this->seller($invoice)));
        $agreement->appendChild($this->party($doc, 'ram:BuyerTradeParty', $this->buyer($invoice)));

        return $agreement;
    }

    /** A seller/buyer trade party (BG-4/BG-7): name, postal address, electronic address (BT-34/49), VAT id (BT-31/48). */
    private function party(DOMDocument $doc, string $wrapper, Party $party): DOMElement
    {
        $node = $doc->createElement($wrapper);
        $this->el($doc, $node, 'ram:Name', $party->name);

        $address = $doc->createElement('ram:PostalTradeAddress');
        $this->el($doc, $address, 'ram:PostcodeCode', $party->postcode);
        $this->el($doc, $address, 'ram:LineOne', $party->address);
        $this->el($doc, $address, 'ram:CityName', $party->city);
        $this->el($doc, $address, 'ram:CountryID', $party->country);
        $node->appendChild($address);

        if ($party->endpointId !== null) {
            $communication = $doc->createElement('ram:URIUniversalCommunication');
            $uri = $this->el($doc, $communication, 'ram:URIID', $party->endpointId);
            $uri->setAttribute('schemeID', $party->endpointScheme);
            $node->appendChild($communication);
        }

        if ($party->vatId !== null) {
            $registration = $doc->createElement('ram:SpecifiedTaxRegistration');
            $id = $this->el($doc, $registration, 'ram:ID', $party->vatId);
            $id->setAttribute('schemeID', 'VA');
            $node->appendChild($registration);
        }

        return $node;
    }

    /**
     * The trade settlement (BG-22): currency, one tax band per rate (BG-23), the totals, and — for a
     * credit note — the preceding-invoice reference (BG-3, BR-55).
     *
     * @param  list<array{rate: float, taxable: int, tax: int}>  $bands
     */
    private function headerSettlement(DOMDocument $doc, array $bands, int $net, int $tax, string $currency, bool $reverseCharge, bool $exempt, InvoiceRecord $invoice): DOMElement
    {
        $settlement = $doc->createElement('ram:ApplicableHeaderTradeSettlement');
        $this->el($doc, $settlement, 'ram:InvoiceCurrencyCode', $currency);

        // BT-120: the exemption reason text, derived from the invoice's vat_note column — the SAME derivation
        // XRechnung uses, so the two syntaxes of one invoice never carry different reason text.
        $exemptionReason = $this->vatNote($invoice, $reverseCharge);

        foreach ($bands as $band) {
            $settlement->appendChild($this->headerTax($doc, $band, $currency, $reverseCharge, $exempt, $invoice, $exemptionReason));
        }

        // BG-14 the period the WHOLE document covers. CII orders ram:BillingSpecifiedPeriod after the tax
        // bands and before the monetary summation, which is the same relative position the LINE-level period
        // takes inside its own settlement — the two are the same term at two levels, not two terms.
        if ($invoice->service_period_start !== null && $invoice->service_period_end !== null) {
            $period = $doc->createElement('ram:BillingSpecifiedPeriod');
            $this->periodDate($doc, $period, 'ram:StartDateTime', $invoice->service_period_start->format('Y-m-d'));
            $this->periodDate($doc, $period, 'ram:EndDateTime', $invoice->service_period_end->format('Y-m-d'));
            $settlement->appendChild($period);
        }

        $settlement->appendChild($this->monetarySummation($doc, $net, $tax, $currency));

        if ($invoice->isCorrection() && $invoice->credited_invoice_number !== null) {
            $referenced = $doc->createElement('ram:InvoiceReferencedDocument');
            $this->el($doc, $referenced, 'ram:IssuerAssignedID', $invoice->credited_invoice_number);
            $settlement->appendChild($referenced);
        }

        return $settlement;
    }

    /**
     * The delivery block (BT-72), which stays EMPTY unless the document records when the supply happened.
     *
     * The element itself is always written: CII requires ApplicableHeaderTradeDelivery between the agreement
     * and the settlement whether or not it has content, and an empty one is exactly what this package has
     * emitted until now. So a document with no delivery date renders byte-for-byte as before.
     *
     * Never derived from the service period's end. A subscription billed in advance is issued on the first
     * of the month, and a derived value would assert a delivery in the future on a document dated before it
     * — in a machine-readable field, to a reader who has no way to tell it was inferred.
     */
    private function headerDelivery(DOMDocument $doc, InvoiceRecord $invoice): DOMElement
    {
        $delivery = $doc->createElement('ram:ApplicableHeaderTradeDelivery');

        if ($invoice->delivered_on !== null) {
            $event = $doc->createElement('ram:ActualDeliverySupplyChainEvent');
            $this->periodDate($doc, $event, 'ram:OccurrenceDateTime', $invoice->delivered_on->format('Y-m-d'));
            $delivery->appendChild($event);
        }

        return $delivery;
    }

    /**
     * A header-level tax band (BG-23). CII order: CalculatedAmount, TypeCode, [ExemptionReason],
     * BasisAmount, CategoryCode, [ExemptionReasonCode], RateApplicablePercent. A reverse charge is
     * category AE at 0% carrying the exemption reason BR-AE-* require — not the zero-rated Z a 0% rate
     * would otherwise get.
     *
     * @param  array{rate: float, taxable: int, tax: int}  $band
     */
    private function headerTax(DOMDocument $doc, array $band, string $currency, bool $reverseCharge, bool $exempt, InvoiceRecord $invoice, ?string $exemptionReason = null): DOMElement
    {
        $tax = $doc->createElement('ram:ApplicableTradeTax');
        $this->amount($doc, $tax, 'ram:CalculatedAmount', $reverseCharge || $exempt ? 0 : $band['tax'], $currency);
        $this->el($doc, $tax, 'ram:TypeCode', 'VAT');

        $category = $this->categoryFor($invoice, $band['rate'], $reverseCharge, $exempt);

        if ($category->needsReason()) {
            // Derived from vat_note where the document carries one, and otherwise the wording that belongs to
            // the category — never hardcoded past that fallback.
            $this->el($doc, $tax, 'ram:ExemptionReason', $exemptionReason ?? $category->reason);
        }

        $this->amount($doc, $tax, 'ram:BasisAmount', $band['taxable'], $currency);
        $this->el($doc, $tax, 'ram:CategoryCode', $category->code);

        // AE, K and G each carry their own VATEX code; an exempt supply (E) needs only its reason text
        // (BR-E-10 accepts the text alone), so no code is emitted for it.
        if ($category->vatexCode !== null) {
            $this->el($doc, $tax, 'ram:ExemptionReasonCode', $category->vatexCode);
        }

        $this->el($doc, $tax, 'ram:RateApplicablePercent', $this->rate($reverseCharge || $exempt ? 0.0 : $band['rate']));

        return $tax;
    }

    /**
     * The document totals (BG-22). CII order: LineTotalAmount, TaxBasisTotalAmount, TaxTotalAmount,
     * GrandTotalAmount, DuePayableAmount. Only TaxTotalAmount carries the currencyID attribute — a CII
     * rule the other summation amounts must NOT repeat.
     */
    private function monetarySummation(DOMDocument $doc, int $net, int $tax, string $currency): DOMElement
    {
        $summation = $doc->createElement('ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->amount($doc, $summation, 'ram:LineTotalAmount', $net, $currency);
        $this->amount($doc, $summation, 'ram:TaxBasisTotalAmount', $net, $currency);
        $taxTotal = $this->amount($doc, $summation, 'ram:TaxTotalAmount', $tax, $currency);
        $taxTotal->setAttribute('currencyID', $currency);
        $this->amount($doc, $summation, 'ram:GrandTotalAmount', $net + $tax, $currency);
        $this->amount($doc, $summation, 'ram:DuePayableAmount', $net + $tax, $currency);

        return $summation;
    }

    /**
     * The EN 16931 category for one supply, from the frozen facts on the document.
     *
     * Deliberately not decided here. Both writers ask the same authority, because two copies of this rule
     * are two places it can drift — and a drift between them has no symptom: each document stays internally
     * consistent, and only a reader comparing a UBL and a CII rendering of the SAME invoice would see it.
     */
    private function categoryFor(InvoiceRecord $invoice, float $rate, bool $reverseCharge, bool $exempt): EnInvoiceTaxCategory
    {
        return EnInvoiceTaxCategory::for(
            $invoice->tax_exemption_reason ?? ($reverseCharge ? TaxExemptionReason::ReverseCharge : null),
            $invoice->tax_archetype,
            $exempt,
            $rate,
            $invoice->destination_country,
        );
    }

    /** A decimal monetary amount element (no currencyID unless the caller adds it — a CII quirk). */
    private function amount(DOMDocument $doc, DOMElement $parent, string $name, int $minor, string $currency): DOMElement
    {
        return $this->el($doc, $parent, $name, Money::of($minor, $currency)->toDecimal());
    }

    /** Create a text element under a parent (text-node escaped, never string-concatenated). */
    private function el(DOMDocument $doc, DOMElement $parent, string $name, string $text): DOMElement
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($text));
        $parent->appendChild($element);

        return $element;
    }

    private function sellerPartyResolver(): SellerPartyResolver
    {
        return $this->sellerResolver;
    }
}
