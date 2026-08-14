<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Fattura :number',
    'invoice' => 'Fattura',
    'correction' => 'Nota di variazione',
    'corrects' => 'Rettifica :number',
    'number' => 'Numero fattura :number',
    'issued' => 'Emessa il :date',
    'from' => 'Da',
    'to' => 'A',
    'vat_id' => 'P. IVA: :id',
    'description' => 'Descrizione',
    'quantity' => 'Qtà',
    'unit_price' => 'Prezzo unitario',
    'vat_rate' => 'IVA',
    'net' => 'Netto',
    'subtotal' => 'Subtotale',
    'vat' => 'IVA',
    'vat_reverse_charge' => 'IVA (inversione contabile)',
    'total' => 'Totale',
    'total_including_vat' => 'Totale IVA :rate inclusa',
    'reverse_charge_note' => 'Inversione contabile: l’imposta è dovuta dal destinatario.',
    'small_business_note' => 'Esente da IVA ai sensi del regime delle piccole imprese (§ 19 UStG).',
    'union_small_business_note' => 'Esente da IVA ai sensi del regime delle piccole imprese dello Stato membro del fornitore.',
    'margin_scheme_note' => 'Regime del margine — beni usati',
    'self_billed_note' => 'Autofattura: emessa dal destinatario per conto del fornitore, previo accordo.',
];
