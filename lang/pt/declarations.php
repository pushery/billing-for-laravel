<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Data de receção',
        'kind' => 'Tipo de cancelamento',
        'reason' => 'Motivo',
        'name' => 'Nome',
        'email' => 'Endereço de e-mail',
        'contract' => 'Contrato',
        'ends' => 'O contrato deve terminar',
        'confirmation_sent_to' => 'Confirmação enviada para',
    ],
    'cancellation' => [
        'button' => 'Cancelar agora',
        'declared_through' => 'Declarado ao premir o botão «:button» em :app.',
        'kinds' => [
            'ordinary' => 'Cancelamento ordinário',
            'extraordinary' => 'Cancelamento extraordinário',
        ],
        'ends' => [
            'earliest_possible' => 'Na data mais próxima possível',
            'earliest_possible_on' => 'Na data mais próxima possível, ou seja, a :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirmar a livre resolução',
        'declared_through' => 'Declarada ao premir o botão «:button» em :app.',
    ],
    'contract_name' => 'Subscrição :tier',
    'contract_unnamed' => 'Subscrição',
];
