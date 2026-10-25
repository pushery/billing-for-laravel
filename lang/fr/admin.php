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

    'datev' => [
        'heading' => 'Lot comptable (DATEV)',
        'intro' => 'Téléchargez une période sous forme de lot comptable DATEV EXTF — le fichier qu\'un conseiller fiscal allemand importe. C\'est le même lot que celui produit par l\'export planifié, rien n\'est stocké sur le serveur, et si la période ne peut pas être comptabilisée en un seul lot, le téléchargement est refusé plutôt que tronqué.',
        'from' => 'Du',
        'to' => 'Au',
        'submit' => 'Télécharger le lot',
        'invalid_period' => 'Indiquez une date de début et une date de fin, la fin ne devant pas précéder le début.',
        'refused' => 'Le lot a été refusé et rien n\'a été téléchargé :',
        'unbalanced' => 'Ce lot n\'est pas équilibré et ne doit pas être transmis.',
        'imbalance_figures' => 'Le grand livre auxiliaire porte :subledger de dettes envers les marchands ; le lot exporté comptabilise :batch sur les comptes de dettes, soit un écart de :difference.',
        'download_anyway' => 'Le télécharger quand même',
        'unbooked' => 'Ce lot laisse :amount de mouvement d\'avoir non comptabilisé — il n\'a pas de paire de comptes. Sur tout le reste, le fichier est complet.',
        'unbooked_none' => 'Aucun mouvement d\'avoir n\'est resté non comptabilisé.',
    ],

    'cancel' => [
        'heading' => 'Résilier un abonnement',
        'intro' => 'Met fin immédiatement à l\'abonnement d\'un titulaire. La modification est consignée dans le journal d\'audit à ton nom.',
        'owner_id' => 'ID du titulaire',
        'submit' => 'Résilier l\'abonnement',
        'canceled' => 'Abonnement résilié.',
        'not_found' => 'Aucun titulaire trouvé pour cet ID.',
        'nothing_running' => 'Ce titulaire n\'a aucun abonnement en cours à résilier.',
    ],

    'declarations' => [
        'heading' => 'Déclarations qui t\'attendent',
        'intro' => 'Résiliations et rétractations par le bouton de résiliation et la fonction de rétractation qui n\'ont pas été exécutées, la plus ancienne d\'abord. Chacune compte à partir du moment où elle est arrivée. Règle-la une fois que tu t\'en es occupé.',
        'empty' => 'Aucune déclaration n\'attend une personne.',
        'cancellation' => 'Résiliation',
        'withdrawal' => 'Rétractation',
        'failure' => 'Son exécution a échoué : :failure.',
        'ends_on' => 'Le contrat prend fin le (facultatif)',
        'resolve' => 'Régler',
        'resolved' => 'Déclaration réglée.',
        'gone' => 'Cette déclaration n\'attend plus une personne.',
        'invalid_day' => 'Saisis le jour sous forme de date.',
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
