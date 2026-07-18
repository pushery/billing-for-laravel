<?php

declare(strict_types=1);

return [

    'title' => 'Facturatiebeheer',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Statistieken',
        'mrr' => 'MRR',
        'active' => 'Actieve abonnementen',
        'trials' => 'In proefperiode',
        'dunning' => 'In aanmaning',
        'churned' => 'Opgezegd (:days d)',
    ],

    'comp' => [
        'heading' => 'Tarief toekennen',
        'intro' => 'Ken een eigenaar buiten de reguliere facturatie een tarief toe. Gebruik een tarief uit billing.untouchable_tiers zodat de volgende provider-webhook het niet overschrijft.',
        'owner_id' => 'Eigenaar-ID',
        'tier' => 'Tarief',
        'submit' => 'Tarief toekennen',
        'granted' => 'Tarief toegekend.',
        'not_found' => 'Geen eigenaar gevonden voor dit ID.',
        'invalid_tier' => 'Dat tarief is niet geconfigureerd in billing.tiers.',
    ],

    'audit' => [
        'heading' => 'Recente activiteit',
        'type' => 'Gebeurtenis',
        'source' => 'Bron',
        'subject' => 'Onderwerp',
        'when' => 'Wanneer',
        'empty' => 'Nog geen facturatiegebeurtenissen vastgelegd.',
    ],

    'source' => [
        'customer' => 'Klant',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'Systeem',
    ],

];
