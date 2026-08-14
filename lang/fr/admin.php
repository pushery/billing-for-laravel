<?php

declare(strict_types=1);

return [

    'title' => 'Administration de la facturation',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Indicateurs',
        'mrr' => 'MRR',
        'active' => 'Abonnements actifs',
        'trials' => 'En essai',
        'dunning' => 'En relance',
        'churned' => 'Résiliés (:days j)',
    ],

    'comp' => [
        'heading' => 'Accorder un forfait',
        'intro' => 'Accorde un forfait à un titulaire de manière exceptionnelle. Utilise un forfait listé dans billing.untouchable_tiers pour que le prochain webhook du fournisseur ne l’écrase pas.',
        'owner_id' => 'ID du titulaire',
        'tier' => 'Forfait',
        'submit' => 'Accorder le forfait',
        'granted' => 'Forfait accordé.',
        'not_found' => 'Aucun titulaire trouvé pour cet ID.',
        'invalid_tier' => 'Ce forfait n’est pas configuré dans billing.tiers.',
    ],

    'cancel' => [
        'heading' => 'Résilier un abonnement',
        'intro' => 'Met fin immédiatement à l\'abonnement d\'un titulaire. La modification est consignée dans le journal d\'audit à ton nom.',
        'owner_id' => 'ID du titulaire',
        'submit' => 'Résilier l\'abonnement',
        'canceled' => 'Abonnement résilié.',
        'not_found' => 'Aucun titulaire trouvé pour cet ID.',
    ],

    'audit' => [
        'heading' => 'Activité récente',
        'type' => 'Événement',
        'source' => 'Source',
        'subject' => 'Sujet',
        'when' => 'Quand',
        'empty' => 'Aucun événement de facturation enregistré pour l’instant.',
    ],

    'source' => [
        'customer' => 'Client',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'Système',
    ],

];
