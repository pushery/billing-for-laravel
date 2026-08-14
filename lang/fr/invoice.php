<?php

declare(strict_types=1);

// The package's own invoice document (billing::invoice). Informal register; publishable with the views.
return [
    'title' => 'Facture :number',
    'invoice' => 'Facture',
    'correction' => 'Facture rectificative',
    'corrects' => 'Rectifie :number',
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
    'total_including_vat' => 'Total, TVA de :rate incluse',
    'reverse_charge_note' => 'Autoliquidation : le preneur est redevable de la TVA.',
    'small_business_note' => 'Exonéré de TVA en application du régime des petites entreprises (§ 19 UStG).',
    'union_small_business_note' => 'Exonéré de TVA en application du régime des petites entreprises de l\'État membre du fournisseur.',
    'margin_scheme_note' => 'Régime de la marge bénéficiaire — biens d’occasion',
    'self_billed_note' => 'Autofacturation : émise par le destinataire au nom du fournisseur, selon accord préalable.',
];
