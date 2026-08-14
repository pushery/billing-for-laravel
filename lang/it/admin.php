<?php

declare(strict_types=1);

return [

    'title' => 'Amministrazione fatturazione',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Metriche',
        'mrr' => 'MRR',
        'active' => 'Abbonamenti attivi',
        'trials' => 'In prova',
        'dunning' => 'In sollecito',
        'churned' => 'Cessati (:days g)',
    ],

    'comp' => [
        'heading' => 'Concedi un piano',
        'intro' => 'Concedi un piano a un intestatario in via straordinaria. Usa un piano incluso in billing.untouchable_tiers così che il prossimo webhook del provider non lo sovrascriva.',
        'owner_id' => 'ID intestatario',
        'tier' => 'Piano',
        'submit' => 'Concedi piano',
        'granted' => 'Piano concesso.',
        'not_found' => 'Nessun intestatario trovato per questo ID.',
        'invalid_tier' => 'Questo piano non è configurato in billing.tiers.',
    ],

    'cancel' => [
        'heading' => 'Annulla un abbonamento',
        'intro' => 'Termina immediatamente l\'abbonamento di un titolare. La modifica viene registrata nel registro di controllo a tuo nome.',
        'owner_id' => 'ID titolare',
        'submit' => 'Annulla abbonamento',
        'canceled' => 'Abbonamento annullato.',
        'not_found' => 'Nessun titolare trovato per questo ID.',
    ],

    'audit' => [
        'heading' => 'Attività recente',
        'type' => 'Evento',
        'source' => 'Origine',
        'subject' => 'Soggetto',
        'when' => 'Quando',
        'empty' => 'Nessun evento di fatturazione registrato finora.',
    ],

    'source' => [
        'customer' => 'Cliente',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'Sistema',
    ],

];
