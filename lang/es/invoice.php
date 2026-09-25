<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Factura :number',
    'invoice' => 'Factura',
    'correction' => 'Factura rectificativa',
    'corrects' => 'Corrige :number',
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
    'small_business_note' => 'Exento de IVA en virtud del régimen de pequeñas empresas (§ 19 UStG).',
    'union_small_business_note' => 'Exento de IVA en virtud del régimen de pequeñas empresas del Estado miembro del proveedor.',
    'not_consideration_note' => 'No sujeto a IVA: indemnización, no contraprestación de una operación.',
    'margin_scheme_note' => 'Régimen del margen de beneficio — bienes de ocasión',
    'margin_scheme_note_works_of_art' => 'Régimen del margen de beneficio — objetos de arte',
    'margin_scheme_note_collectors_items' => 'Régimen del margen de beneficio — objetos de colección y antigüedades',
    'self_billed_note' => 'Autofactura: emitida por el destinatario en nombre del proveedor, por acuerdo previo.',
];
