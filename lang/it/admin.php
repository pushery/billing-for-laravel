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

    'datev' => [
        'heading' => 'Lotto contabile (DATEV)',
        'intro' => 'Scarica un periodo come lotto contabile DATEV EXTF — il file che importa un consulente fiscale tedesco. È lo stesso lotto prodotto dall\'esportazione pianificata, sul server non viene salvato nulla e, se il periodo non può essere contabilizzato come un unico lotto, il download viene rifiutato anziché troncato.',
        'from' => 'Dal',
        'to' => 'Al',
        'submit' => 'Scarica il lotto',
        'invalid_period' => 'Indica una data di inizio e una di fine, con la fine non anteriore all\'inizio.',
        'refused' => 'Il lotto è stato rifiutato e non è stato scaricato nulla:',
        'unbalanced' => 'Questo lotto non quadra e non deve essere trasmesso.',
        'imbalance_figures' => 'Il partitario riporta :subledger di debiti verso i commercianti; il lotto esportato registra :batch sui conti di debito, con una differenza di :difference.',
        'download_anyway' => 'Scaricalo comunque',
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
