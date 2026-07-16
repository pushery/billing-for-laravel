<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\EInvoice;
use Pushery\Billing\Invoicing\Concerns\NormalizesInvoiceModel;
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

    public function __construct(private Repository $config) {}

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
        $creditNote = $invoice->isCreditNote();
        $reverseCharge = (bool) $invoice->reverse_charge;

        $root->appendChild($this->documentContext($doc));
        $root->appendChild($this->document($doc, $reference, $creditNote, $invoice->issued_at ?? Carbon::now()));

        $lines = $this->lines($invoice);
        $bands = $this->taxBandsFor($lines, $reverseCharge);

        // Derive the document net + tax from the lines so the header totals equal the sum of the per-band
        // figures (BR-CO-13/14/15); a lineless invoice falls back to the stored figures.
        $net = $lines === [] ? ($invoice->subtotal_minor ?? $invoice->total_minor) : $this->sum($lines, fn (Line $line): int => $line->netMinor);
        // A reverse charge shifts the VAT to the buyer: the seller charges zero. The per-band CalculatedAmount
        // is already forced to zero (headerTax), so the document tax MUST be zero too — otherwise BT-110 would
        // not equal the sum of the zero bands (BR-CO-14) and the payable would overstate the true net.
        $tax = $reverseCharge ? 0 : ($lines === [] ? ($invoice->tax_minor ?? 0) : $this->sum($bands, fn (array $band): int => $band['tax']));

        $transaction = $doc->createElement('rsm:SupplyChainTradeTransaction');

        foreach ($lines as $index => $line) {
            $transaction->appendChild($this->line($doc, $index + 1, $line, $currency, $reverseCharge));
        }

        $transaction->appendChild($this->headerAgreement($doc, $invoice, $reference));
        $transaction->appendChild($doc->createElement('ram:ApplicableHeaderTradeDelivery'));
        $transaction->appendChild($this->headerSettlement($doc, $bands, $net, $tax, $currency, $reverseCharge, $invoice));

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

    private function document(DOMDocument $doc, string $reference, bool $creditNote, Carbon $issuedAt): DOMElement
    {
        $document = $doc->createElement('rsm:ExchangedDocument');
        $this->el($doc, $document, 'ram:ID', $reference);
        // BT-3 type code: 380 invoice, 381 credit note. The code carries the credit meaning, so amounts
        // stay positive — a negative total would be a non-conformant credit note.
        $this->el($doc, $document, 'ram:TypeCode', $creditNote ? '381' : '380');

        $issue = $doc->createElement('ram:IssueDateTime');
        // Qualifier 102 = CCYYMMDD, the only date format EN 16931 allows for the issue date (BT-2).
        $date = $this->el($doc, $issue, 'udt:DateTimeString', $issuedAt->format('Ymd'));
        $date->setAttribute('format', '102');
        $document->appendChild($issue);

        return $document;
    }

    /** One line item (BG-25): product, net unit price, billed quantity, the line's VAT category and net. */
    private function line(DOMDocument $doc, int $number, Line $line, string $currency, bool $reverseCharge): DOMElement
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
        $settlement->appendChild($this->lineTax($doc, $line->taxRate, $reverseCharge));
        $summation = $doc->createElement('ram:SpecifiedTradeSettlementLineMonetarySummation');
        $this->amount($doc, $summation, 'ram:LineTotalAmount', $line->netMinor, $currency);
        $settlement->appendChild($summation);
        $item->appendChild($settlement);

        return $item;
    }

    /** The line-level VAT category (BT-151/152): code + rate only; the exemption reason lives on the header band. */
    private function lineTax(DOMDocument $doc, float $rate, bool $reverseCharge): DOMElement
    {
        $tax = $doc->createElement('ram:ApplicableTradeTax');
        $this->el($doc, $tax, 'ram:TypeCode', 'VAT');
        $this->el($doc, $tax, 'ram:CategoryCode', $this->category($rate, $reverseCharge));
        $this->el($doc, $tax, 'ram:RateApplicablePercent', $this->rate($reverseCharge ? 0.0 : $rate));

        return $tax;
    }

    private function headerAgreement(DOMDocument $doc, InvoiceRecord $invoice, string $reference): DOMElement
    {
        $agreement = $doc->createElement('ram:ApplicableHeaderTradeAgreement');
        // BT-10 buyer reference: the Leitweg-ID for B2G, the invoice reference for B2B.
        $this->el($doc, $agreement, 'ram:BuyerReference', $this->buyerReference($invoice) ?? $reference);
        $agreement->appendChild($this->party($doc, 'ram:SellerTradeParty', $this->seller()));
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
    private function headerSettlement(DOMDocument $doc, array $bands, int $net, int $tax, string $currency, bool $reverseCharge, InvoiceRecord $invoice): DOMElement
    {
        $settlement = $doc->createElement('ram:ApplicableHeaderTradeSettlement');
        $this->el($doc, $settlement, 'ram:InvoiceCurrencyCode', $currency);

        foreach ($bands as $band) {
            $settlement->appendChild($this->headerTax($doc, $band, $currency, $reverseCharge));
        }

        $settlement->appendChild($this->monetarySummation($doc, $net, $tax, $currency));

        if ($invoice->isCreditNote() && $invoice->credited_invoice_number !== null) {
            $referenced = $doc->createElement('ram:InvoiceReferencedDocument');
            $this->el($doc, $referenced, 'ram:IssuerAssignedID', $invoice->credited_invoice_number);
            $settlement->appendChild($referenced);
        }

        return $settlement;
    }

    /**
     * A header-level tax band (BG-23). CII order: CalculatedAmount, TypeCode, [ExemptionReason],
     * BasisAmount, CategoryCode, [ExemptionReasonCode], RateApplicablePercent. A reverse charge is
     * category AE at 0% carrying the exemption reason BR-AE-* require — not the zero-rated Z a 0% rate
     * would otherwise get.
     *
     * @param  array{rate: float, taxable: int, tax: int}  $band
     */
    private function headerTax(DOMDocument $doc, array $band, string $currency, bool $reverseCharge): DOMElement
    {
        $tax = $doc->createElement('ram:ApplicableTradeTax');
        $this->amount($doc, $tax, 'ram:CalculatedAmount', $reverseCharge ? 0 : $band['tax'], $currency);
        $this->el($doc, $tax, 'ram:TypeCode', 'VAT');

        if ($reverseCharge) {
            $this->el($doc, $tax, 'ram:ExemptionReason', 'Reverse charge');
        }

        $this->amount($doc, $tax, 'ram:BasisAmount', $band['taxable'], $currency);
        $this->el($doc, $tax, 'ram:CategoryCode', $this->category($band['rate'], $reverseCharge));

        if ($reverseCharge) {
            $this->el($doc, $tax, 'ram:ExemptionReasonCode', 'VATEX-EU-AE');
        }

        $this->el($doc, $tax, 'ram:RateApplicablePercent', $this->rate($reverseCharge ? 0.0 : $band['rate']));

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

    /** The VAT category code: AE for a reverse charge, S for a positive rate, Z for a zero rate. */
    private function category(float $rate, bool $reverseCharge): string
    {
        return $reverseCharge ? 'AE' : ($rate > 0 ? 'S' : 'Z');
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

    private function config(): Repository
    {
        return $this->config;
    }
}
