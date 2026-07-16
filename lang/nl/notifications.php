<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Je betaling kon niet worden verwerkt',
        'intro' => 'We konden je laatste betaling niet verwerken.',
        'outro' => 'Werk je betaalgegevens bij om je abonnement actief te houden.',
    ],

    'payment_succeeded' => [
        'subject' => 'Je betalingsbewijs',
        'intro' => 'Bedankt — we hebben je betaling ontvangen.',
        'outro' => 'Een kopie van dit bewijs vind je in je factuurgeschiedenis.',
    ],

    'trial_ending' => [
        'subject' => 'Je proefperiode eindigt binnenkort',
        'intro' => 'Je gratis proefperiode loopt bijna af.',
        'outro' => 'Voeg vóór het einde een betaalmethode toe zodat je abonnement zonder onderbreking doorloopt.',
    ],

    'subscription_canceled' => [
        'subject' => 'Je abonnement is opgezegd',
        'intro' => 'Je abonnement is opgezegd en wordt niet verlengd.',
        'outro' => 'Je behoudt toegang tot het einde van de betaalde periode, hieronder aangegeven.',
    ],

    'suspension_warning' => [
        'subject' => 'Actie nodig: je toegang wordt opgeschort',
        'intro' => 'Je account heeft een achterstallig bedrag en je toegang wordt binnenkort opgeschort.',
        'outro' => 'Betaal het onderstaande bedrag om je toegang te behouden.',
    ],

    'card_expiring' => [
        'subject' => 'Je kaart verloopt binnenkort',
        'intro' => 'De opgeslagen kaart (:card) verloopt binnenkort.',
        'outro' => 'Werk je betaalmethode bij om een onderbreking van je abonnement te voorkomen.',
    ],

    'payment_method_removed' => [
        'subject' => 'Een betaalmethode is verwijderd',
        'intro' => 'Een betaalmethode die voor je abonnement kon worden belast, is uit je account verwijderd.',
        'outro' => 'Als jij dit niet was, voeg dan een nieuwe betaalmethode toe om je abonnement actief te houden.',
    ],

    'quota_warning' => [
        'subject' => 'Je nadert je :meter-limiet',
        'intro' => 'Je hebt deze periode :used van je :included inbegrepen :meter gebruikt.',
        'outro' => 'Vul aan of stap over om zonder onderbreking door te gaan.',
    ],

    'subscription_activated' => [
        'subject' => 'Je abonnement is actief',
        'intro' => 'Je :tier-abonnement is nu actief — alles wat erbij hoort staat aan.',
        'outro' => 'Je kunt je abonnement altijd bekijken of wijzigen in je facturatie-instellingen.',
    ],

    'payment_action_required' => [
        'subject' => 'Bevestig je betaling om door te gaan',
        'intro' => 'Je bank vraagt je deze betaling te bevestigen voordat je abonnement kan doorlopen.',
        'outro' => 'Bevestig het nu om onderbreking van je dienst te voorkomen.',
    ],

];
