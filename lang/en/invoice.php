<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Invoice :number',
    'invoice' => 'Invoice',
    'correction' => 'Invoice correction',
    'corrects' => 'Corrects :number',
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
    'total_including_vat' => 'Total including :rate VAT',
    'reverse_charge_note' => 'Reverse charge: the recipient is liable for the VAT.',
    'small_business_note' => 'Exempt from VAT under the small business scheme (§ 19 UStG).',
    'union_small_business_note' => 'Exempt from VAT under the small business scheme of the supplier\'s member state.',
    'margin_scheme_note' => 'Margin scheme — second-hand goods',
    'self_billed_note' => 'Self-billed invoice: issued by the recipient on behalf of the supplier, by prior agreement.',
];
