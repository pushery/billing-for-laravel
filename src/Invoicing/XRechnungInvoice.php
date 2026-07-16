<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\EInvoice;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * A dependency-free EN 16931 / XRechnung invoice writer. It maps a stored invoice to a UBL 2.1 Invoice
 * document with the mandatory business terms — customization id, number, issue date, type code 380,
 * currency, seller and buyer parties, the per-rate tax breakdown, the document totals, and one line per
 * item — using only PHP's built-in DOM. The seller is the platform (config('billing.company')); the
 * buyer, lines and tax split come from the immutable invoice row. ZUGFeRD (embedding this XML in a
 * PDF/A-3) is a separate writer that needs a PDF library; this plain-XML form is the B2G/B2B baseline.
 */
final readonly class XRechnungInvoice implements EInvoice
{
    private const string UBL = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const string CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0';

    public function __construct(private Repository $config) {}

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

        $creditNote = $invoice->isCreditNote();

        $this->el($doc, $root, 'cbc:CustomizationID', self::CUSTOMIZATION);
        $this->el($doc, $root, 'cbc:ID', $reference);
        $this->el($doc, $root, 'cbc:IssueDate', ($invoice->issued_at ?? Carbon::now())->format('Y-m-d'));
        // BT-3 Type code: 380 for an invoice, 381 for a credit note. Expressing a credit note in Invoice
        // syntax with type 381 is a valid EN 16931 credit note — the code, not a negative amount, carries
        // the credit meaning, so the amounts below stay positive.
        $this->el($doc, $root, 'cbc:InvoiceTypeCode', $creditNote ? '381' : '380');
        $this->el($doc, $root, 'cbc:DocumentCurrencyCode', $currency);
        // BT-10 Buyer reference (the Leitweg-ID for B2G); defaults to the invoice reference for B2B.
        $this->el($doc, $root, 'cbc:BuyerReference', $this->buyerReference($invoice) ?? $reference);

        // BG-3 Preceding invoice reference: a credit note must name the invoice it credits (BR-55). UBL
        // orders cac:BillingReference after cbc:BuyerReference and before the parties.
        if ($creditNote && $invoice->credited_invoice_number !== null) {
            $root->appendChild($this->billingReference($doc, $invoice->credited_invoice_number));
        }

        $root->appendChild($this->party($doc, 'cac:AccountingSupplierParty', $this->seller()));
        $root->appendChild($this->party($doc, 'cac:AccountingCustomerParty', $this->buyer($invoice)));

        $lines = $this->lines($invoice);
        $bands = $this->taxBands($lines);

        // An intra-EU B2B reverse charge: the buyer accounts for the VAT, so every band and line is VAT
        // category AE at 0%, with an exemption reason on the document band — not the zero-rated Z a 0% rate
        // would otherwise get (a conformant EN 16931 validator rejects Z here).
        $reverseCharge = (bool) $invoice->reverse_charge;

        // Derive the document net + tax from the lines so BT-110 equals the sum of the per-band tax
        // (BR-CO-14) and the totals stay internally consistent (BR-CO-13/15). A lineless invoice
        // cannot carry a breakdown, so it falls back to the stored figures.
        $net = $lines === [] ? ($invoice->subtotal_minor ?? $invoice->total_minor) : $this->sum($lines, fn (Line $line): int => $line->netMinor);
        $tax = $lines === [] ? ($invoice->tax_minor ?? 0) : $this->sum($bands, fn (array $band): int => $band['tax']);

        $root->appendChild($this->taxTotal($doc, $bands, $tax, $currency, $reverseCharge));
        $root->appendChild($this->monetaryTotal($doc, $net, $tax, $currency));

        foreach ($lines as $index => $line) {
            $root->appendChild($this->line($doc, $index + 1, $line, $currency, $reverseCharge));
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
     *
     * @param  list<array{rate: float, taxable: int, tax: int}>  $bands
     */
    private function taxTotal(DOMDocument $doc, array $bands, int $tax, string $currency, bool $reverseCharge): DOMElement
    {
        $node = $doc->createElement('cac:TaxTotal');
        $this->money($doc, $node, 'cbc:TaxAmount', $tax, $currency);

        foreach ($bands as $band) {
            $subtotal = $doc->createElement('cac:TaxSubtotal');
            $this->money($doc, $subtotal, 'cbc:TaxableAmount', $band['taxable'], $currency);
            $this->money($doc, $subtotal, 'cbc:TaxAmount', $band['tax'], $currency);
            // The document-level band carries the exemption reason (BT-120/121) for a reverse charge.
            $subtotal->appendChild($this->taxCategory($doc, 'cac:TaxCategory', $band['rate'], $reverseCharge, withReason: true));

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
    private function taxCategory(DOMDocument $doc, string $name, float $rate, bool $reverseCharge, bool $withReason = false): DOMElement
    {
        $category = $doc->createElement($name);
        $this->el($doc, $category, 'cbc:ID', $reverseCharge ? 'AE' : ($rate > 0 ? 'S' : 'Z'));
        $this->el($doc, $category, 'cbc:Percent', $this->rate($reverseCharge ? 0.0 : $rate));

        if ($reverseCharge && $withReason) {
            $this->el($doc, $category, 'cbc:TaxExemptionReasonCode', 'VATEX-EU-AE');
            $this->el($doc, $category, 'cbc:TaxExemptionReason', 'Reverse charge');
        }

        $scheme = $doc->createElement('cac:TaxScheme');
        $this->el($doc, $scheme, 'cbc:ID', 'VAT');
        $category->appendChild($scheme);

        return $category;
    }

    private function line(DOMDocument $doc, int $number, Line $line, string $currency, bool $reverseCharge): DOMElement
    {
        $node = $doc->createElement('cac:InvoiceLine');
        $this->el($doc, $node, 'cbc:ID', (string) $number);

        $quantity = $this->el($doc, $node, 'cbc:InvoicedQuantity', $line->quantity);
        $quantity->setAttribute('unitCode', $line->unit);

        $this->money($doc, $node, 'cbc:LineExtensionAmount', $line->netMinor, $currency);

        $item = $doc->createElement('cac:Item');
        $this->el($doc, $item, 'cbc:Name', $line->description);
        $item->appendChild($this->taxCategory($doc, 'cac:ClassifiedTaxCategory', $line->taxRate, $reverseCharge));
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

    /** The buyer's BT-10 reference (Leitweg-ID) from the stored buyer snapshot, or null. */
    private function buyerReference(InvoiceRecord $invoice): ?string
    {
        $buyer = $invoice->getAttribute('buyer');
        $reference = is_array($buyer) ? ($buyer['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
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
            $key = $this->rate($line->taxRate);
            $taxable[$key] = ($taxable[$key] ?? 0) + $line->netMinor;
            $rates[$key] = $line->taxRate;
        }

        $bands = [];

        foreach ($taxable as $key => $sum) {
            $rate = $rates[$key];
            $bands[] = ['rate' => $rate, 'taxable' => $sum, 'tax' => (int) round($sum * $rate / 100)];
        }

        return $bands;
    }

    /** A tax rate as a plain percentage string, e.g. 19.0 → "19", 25.5 → "25.5". */
    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private function seller(): Party
    {
        return Party::fromArray($this->companyArray('billing.company'));
    }

    private function buyer(InvoiceRecord $invoice): Party
    {
        $buyer = $invoice->getAttribute('buyer');

        return Party::fromArray(is_array($buyer) ? $buyer : []);
    }

    /** @return array<array-key, mixed> */
    private function companyArray(string $key): array
    {
        $value = $this->config->get($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<Line>
     */
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
}
