<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Rechnung :number',
    'invoice' => 'Rechnung',
    'correction' => 'Rechnungskorrektur',
    'corrects' => 'Korrektur zu :number',
    'number' => 'Rechnungsnummer :number',
    'issued' => 'Ausgestellt am :date',
    'from' => 'Von',
    'to' => 'An',
    'vat_id' => 'USt-IdNr.: :id',
    'description' => 'Beschreibung',
    'quantity' => 'Menge',
    'unit_price' => 'Einzelpreis',
    'vat_rate' => 'USt',
    'net' => 'Netto',
    'subtotal' => 'Zwischensumme',
    'vat' => 'USt',
    'vat_reverse_charge' => 'USt (Reverse Charge)',
    'total' => 'Gesamt',
    'total_including_vat' => 'Gesamt inklusive :rate USt',
    'reverse_charge_note' => 'Reverse Charge: Die Steuerschuld geht auf den Leistungsempfänger über.',
    'small_business_note' => 'Steuerfrei nach § 19 UStG (Kleinunternehmerregelung).',
    'union_small_business_note' => 'Steuerfrei nach der Kleinunternehmerregelung des Ansässigkeitsstaats des Leistenden.',
    'margin_scheme_note' => 'Gebrauchtgegenstände/Sonderregelung',
    'self_billed_note' => 'Gutschrift: vom Leistungsempfänger im Namen des Leistenden ausgestellt, nach vorheriger Vereinbarung.',
];
