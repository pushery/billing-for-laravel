<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Factuur :number',
    'invoice' => 'Factuur',
    'correction' => 'Factuurcorrectie',
    'number' => 'Factuurnummer :number',
    'issued' => 'Uitgegeven op :date',
    'from' => 'Van',
    'to' => 'Aan',
    'vat_id' => 'Btw-nummer: :id',
    'description' => 'Omschrijving',
    'quantity' => 'Aantal',
    'unit_price' => 'Stukprijs',
    'vat_rate' => 'Btw',
    'net' => 'Netto',
    'subtotal' => 'Subtotaal',
    'vat' => 'Btw',
    'vat_reverse_charge' => 'Btw (verlegd)',
    'total' => 'Totaal',
    'total_including_vat' => 'Totaal inclusief :rate btw',
    'reverse_charge_note' => 'Btw verlegd: de afnemer is de btw verschuldigd.',
    'margin_scheme_note' => 'Margeregeling — gebruikte goederen',
    'self_billed_note' => 'Self-billing: opgesteld door de afnemer namens de leverancier, op grond van een voorafgaande afspraak.',
];
