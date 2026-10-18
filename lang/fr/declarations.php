<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Date de réception',
        'kind' => 'Type de résiliation',
        'reason' => 'Motif',
        'name' => 'Nom',
        'email' => 'Adresse e-mail',
        'contract' => 'Contrat',
        'ends' => 'Le contrat doit prendre fin',
        'confirmation_sent_to' => 'Confirmation envoyée à',
    ],
    'cancellation' => [
        'button' => 'Résilier maintenant',
        'declared_through' => 'Déclarée en appuyant sur le bouton « :button » sur :app.',
        'kinds' => [
            'ordinary' => 'Résiliation ordinaire',
            'extraordinary' => 'Résiliation extraordinaire',
        ],
        'ends' => [
            'earliest_possible' => 'À la date la plus proche possible',
            'earliest_possible_on' => 'À la date la plus proche possible, soit le :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirmer la rétractation',
        'declared_through' => 'Déclarée en appuyant sur le bouton « :button » sur :app.',
    ],
    'contract_name' => 'Abonnement :tier',
    'contract_unnamed' => 'Abonnement',
];
