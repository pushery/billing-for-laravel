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
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'het einde van de eerste looptijd van het contract is niet bekend, dus het vroegste einde kon niet worden berekend',
        'refund_refused' => 'het contract is beëindigd, en de betaalprovider heeft de terugbetaling geweigerd die de persoon toekomt',
        'withdrawal_window_closed' => 'de herroepingstermijn was verstreken toen de herroeping binnenkwam',
        'withdrawal_unavailable' => 'het abonnement kon niet automatisch worden herroepen, bijvoorbeeld omdat er geen lopend abonnement werd gevonden of zijn niveau consumenten geen herroepingsrecht geeft',
        'cancellation_unavailable' => 'het contract kon niet automatisch worden beëindigd, omdat er geen lopend abonnement werd gevonden of er nog een betaling voor wordt geïnd',
        'end_inside_period_is_final' => 'het abonnement eindigt al op een moment binnen zijn periode, en dat einde kan niet worden verschoven',
        'other' => 'de betaalprovider of de applicatie heeft het geweigerd',
    ],
    'contract_name' => 'Abonnement :tier',
    'contract_unnamed' => 'Abonnement',
];
