<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Invoice :number',
    'invoice' => 'Invoice',
    'correction' => 'Invoice correction',
    'number' => 'Invoice number :number',
    'issued' => 'Issued :date',
    'from' => 'From',
    'to' => 'To',
    'vat_id' => 'VAT ID: :id',
    'description' => 'Description',
    'quantity' => 'Qty',
    'unit_price' => 'Unit price',
    'vat_rate' => 'VAT',
    'net' => 'Net',
    'subtotal' => 'Subtotal',
    'vat' => 'VAT',
    'vat_reverse_charge' => 'VAT (reverse charge)',
    'total' => 'Total',
    'reverse_charge_note' => 'Reverse charge: the recipient is liable for the VAT.',
];
