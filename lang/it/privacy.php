<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Determinazione del luogo della prestazione',
        'purpose' => 'Al momento di un ordine determiniamo in quale paese la prestazione è imponibile e registriamo tale paese, affinché la relativa dichiarazione fiscale possa essere predisposta e in seguito documentata.',
        'legal_basis' => 'Lo facciamo per adempiere a un obbligo di legge in materia fiscale. Non si basa sul tuo consenso né su un bilanciamento di interessi: qui non c\'è nulla da accettare né a cui opporsi, l\'obbligo ci riguarda comunque.',
        'data_categories' => 'Conserviamo il codice paese di due lettere. Se per ricavarlo viene usato un indirizzo di rete, tale indirizzo viene eliminato non appena il paese è determinato e non viene né memorizzato, né registrato nei log, né trasmesso.',
        'retention' => 'Il codice paese è conservato dieci anni, il periodo per cui la normativa fiscale richiede che questa prova resti disponibile.',
        'no_consent_note' => 'La determinazione avviene sul nostro server durante l\'elaborazione dell\'ordine ed è strettamente necessaria per completarlo: non richiede quindi alcun banner sui cookie né altro consenso.',
    ],
];
