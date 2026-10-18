<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Received',
        'kind' => 'Kind of cancellation',
        'reason' => 'Reason',
        'name' => 'Name',
        'email' => 'Email address',
        'contract' => 'Contract',
        'ends' => 'The contract should end',
        'confirmation_sent_to' => 'Confirmation sent to',
    ],
    'cancellation' => [
        'button' => 'Cancel now',
        'declared_through' => 'Declared by pressing the button “:button” on :app.',
        'kinds' => [
            'ordinary' => 'Ordinary cancellation',
            'extraordinary' => 'Extraordinary cancellation',
        ],
        'ends' => [
            'earliest_possible' => 'At the earliest possible date',
            'earliest_possible_on' => 'At the earliest possible date, which is :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirm withdrawal',
        'declared_through' => 'Declared by pressing the button “:button” on :app.',
    ],
    'contract_name' => ':tier subscription',
    'contract_unnamed' => 'Subscription',
];
