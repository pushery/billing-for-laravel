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
    'not_consideration_note' => 'Fuori campo IVA: risarcimento, non corrispettivo di un’operazione.',
    'margin_scheme_note' => 'Regime del margine — beni usati',
    'margin_scheme_note_works_of_art' => 'Regime del margine — oggetti d’arte',
    'margin_scheme_note_collectors_items' => 'Regime del margine — oggetti da collezione e di antiquariato',
    'self_billed_note' => 'Autofattura: emessa dal destinatario per conto del fornitore, previo accordo.',
];
