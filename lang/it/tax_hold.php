<?php

declare(strict_types=1);

// Why a merchant can no longer sell or be paid. The event carries a KEY, never a sentence: whether a
// standing blocks is a jurisdiction's rule, and the person reading it may sit in another one.
//
// The lapsed-attestation wording says plainly that the recipient did nothing wrong. They did not — the date
// simply passed — and a notice that reads like an accusation is one they answer defensively instead of
// acting on.

return [

    'attestation_lapsed' => [
        'title' => 'La tua attestazione fiscale è scaduta',
        'body' => "Finché non viene rinnovata non puoi vendere né ricevere pagamenti. Non hai fatto nulla: l'attestazione ha semplicemente raggiunto la sua data finale.",
    ],

    'status_recorded' => [
        'title' => 'La tua posizione fiscale va chiarita',
        'body' => "Finché non viene rinnovata non puoi vendere né ricevere pagamenti. Non hai fatto nulla: l'attestazione ha semplicemente raggiunto la sua data finale.",
    ],

    'deadline_approaching' => [
        'title' => 'Presto ci servirà la tua posizione fiscale',
        'body' => 'Dalla data di scadenza non potremo elaborare vendite né effettuare pagamenti senza di essa. Puoi sistemarla ora con calma — richiede un momento ed evita una interruzione.',
    ],

];
