<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Fatura :number',
    'invoice' => 'Fatura',
    'correction' => 'Fatura retificativa',
    'number' => 'Número da fatura :number',
    'issued' => 'Emitida em :date',
    'from' => 'De',
    'to' => 'Para',
    'vat_id' => 'NIF/IVA: :id',
    'description' => 'Descrição',
    'quantity' => 'Qtd.',
    'unit_price' => 'Preço unitário',
    'vat_rate' => 'IVA',
    'net' => 'Líquido',
    'subtotal' => 'Subtotal',
    'vat' => 'IVA',
    'vat_reverse_charge' => 'IVA (autoliquidação)',
    'total' => 'Total',
    'reverse_charge_note' => 'Autoliquidação: o adquirente é responsável pelo IVA.',
];
