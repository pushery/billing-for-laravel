<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Fatura :number',
    'invoice' => 'Fatura',
    'correction' => 'Fatura retificativa',
    'corrects' => 'Corrige :number',
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
    'total_including_vat' => 'Total com IVA de :rate incluído',
    'reverse_charge_note' => 'Autoliquidação: o adquirente é responsável pelo IVA.',
    'small_business_note' => 'Isento de IVA ao abrigo do regime das pequenas empresas (§ 19 UStG).',
    'union_small_business_note' => 'Isento de IVA ao abrigo do regime das pequenas empresas do Estado-Membro do fornecedor.',
    'margin_scheme_note' => 'Regime da margem de lucro — bens em segunda mão',
    'self_billed_note' => 'Autofaturação: emitida pelo destinatário em nome do fornecedor, mediante acordo prévio.',
];
