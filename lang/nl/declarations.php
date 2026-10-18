<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Datum van ontvangst',
        'kind' => 'Soort opzegging',
        'reason' => 'Reden',
        'name' => 'Naam',
        'email' => 'E-mailadres',
        'contract' => 'Contract',
        'ends' => 'Het contract moet eindigen',
        'confirmation_sent_to' => 'Bevestiging verzonden naar',
    ],
    'cancellation' => [
        'button' => 'Nu opzeggen',
        'declared_through' => 'Verklaard door op de knop „:button” te drukken bij :app.',
        'kinds' => [
            'ordinary' => 'Gewone opzegging',
            'extraordinary' => 'Buitengewone opzegging',
        ],
        'ends' => [
            'earliest_possible' => 'Op de vroegst mogelijke datum',
            'earliest_possible_on' => 'Op de vroegst mogelijke datum, namelijk :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Herroeping bevestigen',
        'declared_through' => 'Verklaard door op de knop „:button” te drukken bij :app.',
    ],
    'contract_name' => 'Abonnement :tier',
    'contract_unnamed' => 'Abonnement',
];
