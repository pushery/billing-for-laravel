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
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'no se conoce el fin del primer plazo del contrato, así que no se pudo calcular su fin más temprano',
        'refund_refused' => 'el contrato ha terminado y el proveedor de pagos rechazó el reembolso que se le debe a la persona',
        'withdrawal_window_closed' => 'el plazo de desistimiento había terminado cuando llegó el desistimiento',
        'withdrawal_unavailable' => 'no se pudo desistir de la suscripción de forma automática, por ejemplo porque no se encontró ninguna suscripción activa o su nivel no da a los consumidores derecho de desistimiento',
        'cancellation_unavailable' => 'no se pudo terminar el contrato de forma automática, porque no se encontró ninguna suscripción activa o todavía se está cobrando un pago de ella',
        'end_inside_period_is_final' => 'la suscripción ya termina en un momento dentro de su periodo, y ese fin no se puede mover',
        'other' => 'el proveedor de pagos o la aplicación la rechazó',
    ],
    'contract_name' => 'Suscripción :tier',
    'contract_unnamed' => 'Suscripción',
];
