<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Détermination du lieu de la prestation',
        'purpose' => 'Lors d\'une commande, nous déterminons dans quel pays la prestation est imposable et conservons ce pays afin que la déclaration fiscale correspondante puisse être établie puis justifiée.',
        'legal_basis' => 'Nous le faisons pour satisfaire à une obligation légale en matière fiscale. Cela ne repose ni sur ton consentement ni sur une mise en balance des intérêts : il n\'y a donc rien à accepter ni à contester ici, l\'obligation s\'impose à nous indépendamment.',
        'data_categories' => 'Nous conservons le code pays à deux lettres. Lorsqu\'une adresse réseau sert à le déterminer, cette adresse est supprimée dès que le pays est établi ; elle n\'est ni conservée, ni journalisée, ni transmise.',
        'retention' => 'Le code pays est conservé dix ans, durée pendant laquelle le droit fiscal exige que cette preuve reste disponible.',
        'no_consent_note' => 'La détermination a lieu sur notre serveur pendant le traitement de la commande et est strictement nécessaire à son exécution ; elle ne requiert donc ni bandeau cookies ni autre consentement.',
    ],
];
