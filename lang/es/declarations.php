<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Fecha de recepción',
        'kind' => 'Tipo de cancelación',
        'reason' => 'Motivo',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'contract' => 'Contrato',
        'ends' => 'El contrato debe terminar',
        'confirmation_sent_to' => 'Confirmación enviada a',
    ],
    'cancellation' => [
        'button' => 'Cancelar ahora',
        'declared_through' => 'Declarada pulsando el botón «:button» en :app.',
        'kinds' => [
            'ordinary' => 'Cancelación ordinaria',
            'extraordinary' => 'Cancelación extraordinaria',
        ],
        'ends' => [
            'earliest_possible' => 'En la fecha más temprana posible',
            'earliest_possible_on' => 'En la fecha más temprana posible, que es el :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirmar desistimiento',
        'declared_through' => 'Declarado pulsando el botón «:button» en :app.',
    ],
    'contract_name' => 'Suscripción :tier',
    'contract_unnamed' => 'Suscripción',
];
