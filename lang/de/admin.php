<?php

declare(strict_types=1);

return [

    'title' => 'Abrechnung — Admin',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Kennzahlen',
        'mrr' => 'MRR',
        'active' => 'Aktive Abos',
        'trials' => 'In Testphase',
        'dunning' => 'Im Mahnlauf',
        'churned' => 'Gekündigt (:days T)',
    ],

    'comp' => [
        'heading' => 'Tarif gewähren',
        'intro' => 'Gewähre einem Inhaber einen Tarif außerhalb der regulären Abrechnung. Verwende einen Tarif aus billing.untouchable_tiers, damit der nächste Provider-Webhook ihn nicht überschreibt.',
        'owner_id' => 'Inhaber-ID',
        'tier' => 'Tarif',
        'submit' => 'Tarif gewähren',
        'granted' => 'Tarif gewährt.',
        'not_found' => 'Kein Inhaber mit dieser ID gefunden.',
        'invalid_tier' => 'Dieser Tarif ist nicht in billing.tiers konfiguriert.',
    ],

    'audit' => [
        'heading' => 'Letzte Aktivität',
        'type' => 'Ereignis',
        'source' => 'Quelle',
        'subject' => 'Betrifft',
        'when' => 'Zeitpunkt',
        'empty' => 'Noch keine Abrechnungsereignisse erfasst.',
    ],

    'source' => [
        'customer' => 'Kunde',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'System',
    ],

];
