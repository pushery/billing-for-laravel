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
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'Das Ende der ersten Laufzeit des Vertrags ist nicht bekannt, deshalb ließ sich sein frühestes Ende nicht berechnen',
        'refund_refused' => 'Der Vertrag ist beendet, und der Zahlungsanbieter hat die Erstattung abgelehnt, die der Person zusteht',
        'withdrawal_window_closed' => 'Die Widerrufsfrist war abgelaufen, als der Widerruf einging',
        'withdrawal_unavailable' => 'Das Abo ließ sich nicht automatisch widerrufen, etwa weil kein laufendes Abo gefunden wurde oder seine Stufe Verbrauchern kein Widerrufsrecht gibt',
        'cancellation_unavailable' => 'Der Vertrag ließ sich nicht automatisch beenden, weil kein laufendes Abo gefunden wurde oder eine Zahlung dafür noch eingezogen wird',
        'end_inside_period_is_final' => 'Das Abo endet schon zu einem Zeitpunkt innerhalb seines Zeitraums, und dieses Ende lässt sich nicht verschieben',
        'other' => 'Der Zahlungsanbieter oder die Anwendung hat sie abgelehnt',
    ],
    'contract_name' => 'Abo :tier',
    'contract_unnamed' => 'Abo',
];
