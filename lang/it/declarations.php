<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Data di ricezione',
        'kind' => 'Tipo di disdetta',
        'reason' => 'Motivo',
        'name' => 'Nome',
        'email' => 'Indirizzo email',
        'contract' => 'Contratto',
        'ends' => 'Il contratto deve terminare',
        'confirmation_sent_to' => 'Conferma inviata a',
    ],
    'cancellation' => [
        'button' => 'Disdici ora',
        'declared_through' => 'Dichiarata premendo il pulsante «:button» su :app.',
        'kinds' => [
            'ordinary' => 'Disdetta ordinaria',
            'extraordinary' => 'Disdetta straordinaria',
        ],
        'ends' => [
            'earliest_possible' => 'Alla prima data possibile',
            'earliest_possible_on' => 'Alla prima data possibile, ossia il :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Conferma il recesso',
        'declared_through' => 'Dichiarato premendo il pulsante «:button» su :app.',
    ],
    'contract_name' => 'Abbonamento :tier',
    'contract_unnamed' => 'Abbonamento',
];
