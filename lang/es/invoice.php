<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Factura :number',
    'invoice' => 'Factura',
    'correction' => 'Factura rectificativa',
    'number' => 'Número de factura :number',
    'issued' => 'Emitida el :date',
    'from' => 'De',
    'to' => 'Para',
    'vat_id' => 'NIF/IVA: :id',
    'description' => 'Descripción',
    'quantity' => 'Cant.',
    'unit_price' => 'Precio unitario',
    'vat_rate' => 'IVA',
    'net' => 'Neto',
    'subtotal' => 'Subtotal',
    'vat' => 'IVA',
    'vat_reverse_charge' => 'IVA (inversión del sujeto pasivo)',
    'total' => 'Total',
    'total_including_vat' => 'Total con IVA del :rate incluido',
    'reverse_charge_note' => 'Inversión del sujeto pasivo: el destinatario es responsable del IVA.',
    'margin_scheme_note' => 'Régimen del margen de beneficio — bienes de ocasión',
    'self_billed_note' => 'Autofactura: emitida por el destinatario en nombre del proveedor, por acuerdo previo.',
];
