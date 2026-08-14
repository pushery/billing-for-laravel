<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

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
use Pushery\Billing\ValueObjects\EnInvoiceTaxTreatment;
use Pushery\Billing\ValueObjects\Money;

/**
 * A dependency-free EN 16931 / XRechnung invoice writer. It maps a stored invoice to a UBL 2.1 Invoice
 * document with the mandatory business terms — customization id, number, issue date, type code 380,
 * currency, seller and buyer parties, the per-rate tax breakdown, the document totals, and one line per
 * item — using only PHP's built-in DOM. The seller comes from the row's frozen `seller` snapshot, falling
 * back to the resolver whose default is the platform; the buyer, lines and tax split come from the immutable
 * invoice row. ZUGFeRD (embedding this XML in a PDF/A-3) is a separate writer that needs a PDF library; this
 * plain-XML form is the B2G/B2B baseline.
 */
final readonly class XRechnungInvoice implements EInvoice
{
    use NormalizesInvoiceModel;

    private const string UBL = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const string CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0';

    private SellerPartyResolver $sellerResolver;

    public function __construct(
        Repository $config,
        ?SellerPartyResolver $sellerResolver = null,
    ) {
        // Optional and last, so `new XRechnungInvoice($config)` still constructs. It defaults to the platform
        // company — the single-seller answer — so nothing about the rendered output changes without a binding.
        // Config is only needed to build that default resolver; the writer keeps no config of its own.
        $this->sellerResolver = $sellerResolver ?? new ConfigSellerPartyResolver($config);
    }

    public function render(InvoiceRecord $invoice): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::UBL, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $doc->appendChild($root);

        $currency = $invoice->currency;

        $reference = $invoice->number ?? (string) $invoice->id;

        $correction = $invoice->isCorrection();

        $this->el($doc, $root, 'cbc:CustomizationID', self::CUSTOMIZATION);
        $this->el($doc, $root, 'cbc:ID', $reference);
        $this->el($doc, $root, 'cbc:IssueDate', ($invoice->issued_at ?? Carbon::now())->format('Y-m-d'));
        // BT-3 Type code: 380 invoice, 381 cancellation, 384 amendment, 389 self-billed invoice — derived
        // once in the shared trait so UBL and CII never disagree. The code, not a negative amount, carries
        // the correcting meaning, so the amounts below stay positive.
        //
        // All FOUR are named on purpose. This sentence used to list three and leave out 384, which reads as
        // the complete table and is the one omission that matters here: a cancellation and an amendment are
        // two different documents that a tax authority tells apart by this code alone.
        $this->el($doc, $root, 'cbc:InvoiceTypeCode', $this->typeCode($invoice));
        $this->el($doc, $root, 'cbc:DocumentCurrencyCode', $currency);
        // BT-10 Buyer reference (the Leitweg-ID for B2G); defaults to the invoice reference for B2B.
        $this->el($doc, $root, 'cbc:BuyerReference', $this->buyerReference($invoice) ?? $reference);

        // BT-22: a self-billed document has to SAY it is one. Without the statement it reads as an ordinary
        // invoice issued by the wrong party, and the recipient cannot tell it was written under an
        // arrangement they agreed to. UBL puts a document-level note after the type code and before the
        // references, so it goes here — and it is absent entirely on every other document.
        $selfBilled = $this->selfBillingNote($invoice);

        if ($selfBilled !== null) {
            $this->el($doc, $root, 'cbc:Note', $selfBilled);
        }

        // BG-14 the period the WHOLE document covers (BT-73 start, BT-74 end). Distinct from the periods on
        // the lines below: a reader asks which months the document is for before reading a line, and
        // answering that by reducing a set of line periods means every reader has to agree on how. UBL
        // orders cac:InvoicePeriod after cbc:BuyerReference and before the references, so it goes here.
        // Absent entirely on a document that states no period, which keeps every existing one byte-identical.
        if ($invoice->service_period_start !== null && $invoice->service_period_end !== null) {
            $period = $doc->createElement('cac:InvoicePeriod');
            $this->el($doc, $period, 'cbc:StartDate', $invoice->service_period_start->format('Y-m-d'));
            $this->el($doc, $period, 'cbc:EndDate', $invoice->service_period_end->format('Y-m-d'));
            $root->appendChild($period);
        }

        // BG-3 Preceding invoice reference: a correction must name the invoice it corrects (BR-55). UBL
        // orders cac:BillingReference after cbc:BuyerReference and before the parties.
        if ($correction && $invoice->credited_invoice_number !== null) {
            $root->appendChild($this->billingReference($doc, $invoice->credited_invoice_number));
        }

        $root->appendChild($this->party($doc, 'cac:AccountingSupplierParty', $this->seller($invoice)));
        $root->appendChild($this->party($doc, 'cac:AccountingCustomerParty', $this->buyer($invoice)));

        // BT-72 the date the supply was actually made. Written ONLY from the recorded date, never derived
        // from the period's end: a subscription billed in advance issues on the first of the month, and a
        // derived value would state a delivery in the future on a document dated before it. UBL orders
        // cac:Delivery after the parties and before the payment and tax blocks.
        if ($invoice->delivered_on !== null) {
            $delivery = $doc->createElement('cac:Delivery');
            $this->el($doc, $delivery, 'cbc:ActualDeliveryDate', $invoice->delivered_on->format('Y-m-d'));
            $root->appendChild($delivery);
        }

        // How this document is taxed, derived ONCE. An intra-EU B2B reverse charge makes every band and line
        // VAT category AE at 0% with an exemption reason on the document band — not the zero-rated Z a 0%
        // rate would otherwise get, which a conformant EN 16931 validator rejects here.
        //
        // These five lines used to stand here and, byte for byte, in the CII writer as well. Two readings of
        // one rule drift, and the drift is format-specific: it appears in whichever of the two nobody is
        // looking at.
        $treatment = $this->taxTreatmentFor($invoice);

        $root->appendChild($this->taxTotal($doc, $currency, $treatment));
        $root->appendChild($this->monetaryTotal($doc, $treatment->net, $treatment->tax, $currency));

        foreach ($treatment->lines as $index => $line) {
            $root->appendChild($this->line($doc, $index + 1, $line, $currency, $treatment));
        }

        return (string) $doc->saveXML();
    }

    /** Create a text element under a parent (text-node escaped, never string-concatenated). */
    private function el(DOMDocument $doc, DOMElement $parent, string $name, string $text): DOMElement
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($text));
        $parent->appendChild($element);

        return $element;
    }

    /** A preceding-invoice reference (BG-3/BT-25): the number of the invoice a credit note credits. */
    private function billingReference(DOMDocument $doc, string $creditedNumber): DOMElement
    {
        $node = $doc->createElement('cac:BillingReference');
        $documentReference = $doc->createElement('cac:InvoiceDocumentReference');
        $this->el($doc, $documentReference, 'cbc:ID', $creditedNumber);
        $node->appendChild($documentReference);

        return $node;
    }

    /** A supplier/customer party wrapper with postal address, optional VAT scheme and legal name. */
    private function party(DOMDocument $doc, string $wrapper, Party $party): DOMElement
    {
        $node = $doc->createElement($wrapper);
        $partyNode = $doc->createElement('cac:Party');
        $node->appendChild($partyNode);

        if ($party->endpointId !== null) {
            $endpoint = $this->el($doc, $partyNode, 'cbc:EndpointID', $party->endpointId);
            $endpoint->setAttribute('schemeID', $party->endpointScheme);
        }

        $address = $doc->createElement('cac:PostalAddress');
        $this->el($doc, $address, 'cbc:StreetName', $party->address);
        $this->el($doc, $address, 'cbc:CityName', $party->city);
        $this->el($doc, $address, 'cbc:PostalZone', $party->postcode);
        $country = $doc->createElement('cac:Country');
        $this->el($doc, $country, 'cbc:IdentificationCode', $party->country);
        $address->appendChild($country);
        $partyNode->appendChild($address);

        if ($party->vatId !== null) {
            $taxScheme = $doc->createElement('cac:PartyTaxScheme');
            $this->el($doc, $taxScheme, 'cbc:CompanyID', $party->vatId);
            $scheme = $doc->createElement('cac:TaxScheme');
            $this->el($doc, $scheme, 'cbc:ID', 'VAT');
            $taxScheme->appendChild($scheme);
            $partyNode->appendChild($taxScheme);
        }

        $legal = $doc->createElement('cac:PartyLegalEntity');
        $this->el($doc, $legal, 'cbc:RegistrationName', $party->name);
        $partyNode->appendChild($legal);

        return $node;
    }

    /**
     * The tax total plus a subtotal per distinct rate (BG-23). The document-level TaxAmount is the
     * caller-supplied sum of the band taxes, so BT-110 always equals the sum of the BT-117s (BR-CO-14).
     */
    private function taxTotal(DOMDocument $doc, string $currency, EnInvoiceTaxTreatment $treatment): DOMElement
    {
        $node = $doc->createElement('cac:TaxTotal');
        $this->money($doc, $node, 'cbc:TaxAmount', $treatment->tax, $currency);

        foreach ($treatment->bands as $band) {
            $subtotal = $doc->createElement('cac:TaxSubtotal');
            $this->money($doc, $subtotal, 'cbc:TaxableAmount', $band['taxable'], $currency);
            // A reverse-charge (AE) or exempt (E) band carries zero tax — the buyer accounts for it, or there
            // is none (BR-AE-* / BR-E-*).
            $this->money($doc, $subtotal, 'cbc:TaxAmount', $treatment->reverseCharge || $treatment->exempt ? 0 : $band['tax'], $currency);
            // The document-level band carries the exemption reason (BT-120/121) for a reverse charge or exemption.
            $subtotal->appendChild($this->taxCategory($doc, 'cac:TaxCategory', $band['rate'], $treatment, withReason: true));

            $node->appendChild($subtotal);
        }

        return $node;
    }

    private function monetaryTotal(DOMDocument $doc, int $net, int $tax, string $currency): DOMElement
    {
        $node = $doc->createElement('cac:LegalMonetaryTotal');
        $this->money($doc, $node, 'cbc:LineExtensionAmount', $net, $currency);
        $this->money($doc, $node, 'cbc:TaxExclusiveAmount', $net, $currency);
        $this->money($doc, $node, 'cbc:TaxInclusiveAmount', $net + $tax, $currency);
        $this->money($doc, $node, 'cbc:PayableAmount', $net + $tax, $currency);

        return $node;
    }

    /**
     * A VAT category element (TaxCategory / ClassifiedTaxCategory). Normally the category CODE follows the
     * rate: a positive rate is Standard-rated ("S"); a zero rate is Zero-rated ("Z") — emitting "S" with a
     * 0% rate violates EN 16931 BR-S-05/06. A reverse charge overrides both: category "AE" at 0%, and — on
     * the document-level band ($withReason) — the exemption reason (BT-121 code + BT-120 text) that BR-AE-*
     * require. The line-level ClassifiedTaxCategory carries the code + rate only; the reason lives once, on
     * the band. UBL order inside cac:TaxCategory: ID, Percent, TaxExemptionReasonCode/Reason, TaxScheme.
     */
    private function taxCategory(DOMDocument $doc, string $name, float $rate, EnInvoiceTaxTreatment $treatment, bool $withReason = false): DOMElement
    {
        $invoice = $treatment->invoice;
        $reverseCharge = $treatment->reverseCharge;
        $exempt = $treatment->exempt;

        $category = $doc->createElement($name);

        // Decided by the shared authority rather than here. Both writers used to carry their own copy of this
        // rule, and two copies of a rule are two places it can drift — with no symptom, because each document
        // stays internally consistent and only a reader comparing a UBL and a CII rendering of the SAME
        // invoice would ever see them disagree.
        $resolved = EnInvoiceTaxCategory::for(
            $invoice->tax_exemption_reason ?? ($reverseCharge ? TaxExemptionReason::ReverseCharge : null),
            $invoice->tax_archetype,
            $exempt,
            $rate,
            $invoice->destination_country,
        );

        $this->el($doc, $category, 'cbc:ID', $resolved->code);
        $this->el($doc, $category, 'cbc:Percent', $this->rate($reverseCharge || $exempt ? 0.0 : $rate));

        if ($withReason && $resolved->needsReason()) {
            // AE, K and G each carry their own VATEX code; an exempt supply (E) needs only its reason text
            // (BR-E-10 accepts the text alone). BT-120 is the derived reason (from vat_note), falling back to
            // the wording that belongs to the category — never hardcoded past that fallback.
            if ($resolved->vatexCode !== null) {
                $this->el($doc, $category, 'cbc:TaxExemptionReasonCode', $resolved->vatexCode);
            }

            $this->el($doc, $category, 'cbc:TaxExemptionReason', $treatment->exemptionReason ?? $resolved->reason);
        }

        $scheme = $doc->createElement('cac:TaxScheme');
        $this->el($doc, $scheme, 'cbc:ID', 'VAT');
        $category->appendChild($scheme);

        return $category;
    }

    private function line(DOMDocument $doc, int $number, Line $line, string $currency, EnInvoiceTaxTreatment $treatment): DOMElement
    {
        $node = $doc->createElement('cac:InvoiceLine');
        $this->el($doc, $node, 'cbc:ID', (string) $number);

        $quantity = $this->el($doc, $node, 'cbc:InvoicedQuantity', $line->quantity);
        $quantity->setAttribute('unitCode', $line->unit);

        $this->money($doc, $node, 'cbc:LineExtensionAmount', $line->netMinor, $currency);

        // BG-14 the period this line covers (BT-73 start, BT-74 end). Written only when the line states one,
        // so a document without periods is byte-for-byte what it always was. UBL orders cac:InvoicePeriod
        // after the line amount and before the item.
        if ($line->hasPeriod()) {
            $period = $doc->createElement('cac:InvoicePeriod');
            $this->el($doc, $period, 'cbc:StartDate', (string) $line->periodStart);
            $this->el($doc, $period, 'cbc:EndDate', (string) $line->periodEnd);
            $node->appendChild($period);
        }

        $item = $doc->createElement('cac:Item');
        $this->el($doc, $item, 'cbc:Name', $line->description);
        $item->appendChild($this->taxCategory($doc, 'cac:ClassifiedTaxCategory', $line->taxRate, $treatment));
        $node->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $this->money($doc, $price, 'cbc:PriceAmount', $line->unitPriceMinor, $currency);
        $node->appendChild($price);

        return $node;
    }

    /** A monetary element with the currency attribute and a decimal amount. */
    private function money(DOMDocument $doc, DOMElement $parent, string $name, int $minor, string $currency): void
    {
        $element = $this->el($doc, $parent, $name, Money::of($minor, $currency)->toDecimal());
        $element->setAttribute('currencyID', $currency);
    }

    private function sellerPartyResolver(): SellerPartyResolver
    {
        return $this->sellerResolver;
    }
}
