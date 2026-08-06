<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Determinación del lugar de la prestación',
        'purpose' => 'Al hacer un pedido determinamos en qué país tributa la prestación y registramos ese país para poder elaborar y, más adelante, acreditar la declaración fiscal correspondiente.',
        'legal_basis' => 'Lo hacemos para cumplir una obligación legal en materia tributaria. No se basa en tu consentimiento ni en una ponderación de intereses, de modo que aquí no hay nada que aceptar ni a lo que oponerse: la obligación nos alcanza igualmente.',
        'data_categories' => 'Conservamos el código de país de dos letras. Cuando se utiliza una dirección de red para deducirlo, esa dirección se descarta en cuanto se determina el país y no se almacena, ni se registra, ni se transmite.',
        'retention' => 'El código de país se conserva diez años, que es el plazo durante el cual la normativa tributaria exige que esta prueba esté disponible.',
        'no_consent_note' => 'La determinación se realiza en nuestro servidor durante la tramitación del pedido y es estrictamente necesaria para completarlo, por lo que no requiere aviso de cookies ni ningún otro consentimiento.',
    ],
];
