<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Fattura :number',
    'invoice' => 'Fattura',
    'correction' => 'Nota di variazione',
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
    'reverse_charge_note' => 'Inversione contabile: l’imposta è dovuta dal destinatario.',
];
