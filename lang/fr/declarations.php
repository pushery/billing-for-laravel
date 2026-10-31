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
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'la fin de la première durée du contrat n\'est pas connue, si bien que sa fin la plus proche n\'a pas pu être calculée',
        'refund_refused' => 'le contrat a pris fin, et le prestataire de paiement a refusé le remboursement dû à la personne',
        'withdrawal_window_closed' => 'le délai de rétractation était écoulé quand la rétractation est arrivée',
        'withdrawal_unavailable' => 'l\'abonnement n\'a pas pu faire l\'objet d\'une rétractation automatique, par exemple parce qu\'aucun abonnement en cours n\'a été trouvé ou que son niveau ne donne pas de droit de rétractation aux consommateurs',
        'cancellation_unavailable' => 'le contrat n\'a pas pu être résilié automatiquement, parce qu\'aucun abonnement en cours n\'a été trouvé ou qu\'un paiement est encore en cours d\'encaissement',
        'end_inside_period_is_final' => 'l\'abonnement prend déjà fin à un moment situé dans sa période, et cette fin ne peut pas être déplacée',
        'other' => 'le prestataire de paiement ou l\'application l\'a refusée',
    ],
    'contract_name' => 'Abonnement :tier',
    'contract_unnamed' => 'Abonnement',
];
