<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Facture :number',
    'invoice' => 'Facture',
    'correction' => 'Facture rectificative',
    'number' => 'Numéro de facture :number',
    'issued' => 'Émise le :date',
    'from' => 'De',
    'to' => 'À',
    'vat_id' => 'N° TVA : :id',
    'description' => 'Description',
    'quantity' => 'Qté',
    'unit_price' => 'Prix unitaire',
    'vat_rate' => 'TVA',
    'net' => 'Net',
    'subtotal' => 'Sous-total',
    'vat' => 'TVA',
    'vat_reverse_charge' => 'TVA (autoliquidation)',
    'total' => 'Total',
    'reverse_charge_note' => 'Autoliquidation : le preneur est redevable de la TVA.',
];
