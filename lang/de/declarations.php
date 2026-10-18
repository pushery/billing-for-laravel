<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Eingegangen',
        'kind' => 'Art der Kündigung',
        'reason' => 'Grund',
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'contract' => 'Vertrag',
        'ends' => 'Der Vertrag soll enden',
        'confirmation_sent_to' => 'Bestätigung gesendet an',
    ],
    'cancellation' => [
        'button' => 'jetzt kündigen',
        'declared_through' => 'Erklärt durch Klick auf die Schaltfläche „:button“ bei :app.',
        'kinds' => [
            'ordinary' => 'Ordentliche Kündigung',
            'extraordinary' => 'Außerordentliche Kündigung',
        ],
        'ends' => [
            'earliest_possible' => 'Zum nächstmöglichen Zeitpunkt',
            'earliest_possible_on' => 'Zum nächstmöglichen Zeitpunkt, das ist der :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Widerruf bestätigen',
        'declared_through' => 'Erklärt durch Klick auf die Schaltfläche „:button“ bei :app.',
    ],
    'contract_name' => 'Abo :tier',
    'contract_unnamed' => 'Abo',
];
