<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Bestimmung des Leistungsorts',
        'purpose' => 'Bei einer Bestellung bestimmen wir, in welchem Land die Leistung zu versteuern ist, und halten dieses Land fest, damit die zugehörige Steuererklärung erstellt und später belegt werden kann.',
        'legal_basis' => 'Wir tun das zur Erfüllung einer steuerrechtlichen Pflicht. Es beruht weder auf deiner Einwilligung noch auf einer Interessenabwägung — es gibt hier also nichts zuzustimmen und nichts zu widersprechen; die Pflicht trifft uns unabhängig davon.',
        'data_categories' => 'Gespeichert wird der zweistellige Ländercode. Soweit dafür eine Netzwerkadresse ausgewertet wird, wird diese unmittelbar nach der Länderbestimmung verworfen und weder gespeichert noch protokolliert noch weitergegeben.',
        'retention' => 'Der Ländercode wird zehn Jahre aufbewahrt — so lange verlangt das Steuerrecht, dass dieser Nachweis verfügbar ist.',
        'no_consent_note' => 'Die Bestimmung erfolgt auf unserem Server während der Bestellabwicklung und ist für deren Durchführung unbedingt erforderlich; sie benötigt daher weder ein Cookie-Banner noch eine sonstige Einwilligung.',
    ],
];
