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
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'la fine del primo periodo del contratto non è nota, quindi non è stato possibile calcolarne la fine più vicina',
        'refund_refused' => 'il contratto è terminato e il fornitore di pagamento ha rifiutato il rimborso dovuto alla persona',
        'withdrawal_window_closed' => 'il periodo di recesso era scaduto quando è arrivato il recesso',
        'withdrawal_unavailable' => 'non è stato possibile recedere dall\'abbonamento in automatico, per esempio perché non è stato trovato nessun abbonamento attivo o il suo livello non dà ai consumatori il diritto di recesso',
        'cancellation_unavailable' => 'non è stato possibile terminare il contratto in automatico, perché non è stato trovato nessun abbonamento attivo o un pagamento è ancora in fase di incasso',
        'end_inside_period_is_final' => 'l\'abbonamento termina già in un momento all\'interno del suo periodo, e questa fine non si può spostare',
        'other' => 'il fornitore di pagamento o l\'applicazione l\'ha rifiutata',
    ],
    'contract_name' => 'Abbonamento :tier',
    'contract_unnamed' => 'Abbonamento',
];
