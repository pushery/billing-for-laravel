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

    'datev' => [
        'heading' => 'Boekingsbatch (DATEV)',
        'intro' => 'Download een periode als DATEV EXTF-boekingsbatch — het bestand dat een Duitse belastingadviseur importeert. Het is dezelfde batch als bij de geplande export, er wordt niets op de server opgeslagen, en kan de periode niet als één batch worden geboekt, dan wordt de download geweigerd in plaats van afgekapt.',
        'from' => 'Van',
        'to' => 'Tot',
        'submit' => 'Batch downloaden',
        'invalid_period' => 'Geef een begin- en einddatum op; het einde mag niet vóór het begin liggen.',
        'refused' => 'De batch is geweigerd en er is niets gedownload:',
        'unbalanced' => 'Deze batch sluit niet aan en mag niet worden ingediend.',
        'imbalance_figures' => 'Het subgrootboek bevat :subledger aan schulden aan handelaren; de geëxporteerde batch boekt :batch op de schuldrekeningen, een verschil van :difference.',
        'download_anyway' => 'Toch downloaden',
    ],

    'cancel' => [
        'heading' => 'Een abonnement opzeggen',
        'intro' => 'Beëindigt het abonnement van een eigenaar direct. De wijziging wordt met je naam in het auditlogboek vastgelegd.',
        'owner_id' => 'Eigenaar-ID',
        'submit' => 'Abonnement opzeggen',
        'canceled' => 'Abonnement opgezegd.',
        'not_found' => 'Geen eigenaar gevonden met dat ID.',
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
