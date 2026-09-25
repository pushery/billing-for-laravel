<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Ton paiement n’a pas pu être traité',
        'intro' => 'Nous n’avons pas pu traiter ton dernier paiement.',
        'outro' => 'Mets à jour tes informations de paiement pour garder ton abonnement actif.',
        'cta' => 'Mettre à jour les informations de paiement',
    ],

    'payment_succeeded' => [
        'subject' => 'Ton reçu de paiement',
        'intro' => 'Merci — nous avons bien reçu ton paiement.',
        'declarations' => 'Tes déclarations avant le début de la fourniture :',
        'outro' => 'Une copie de ce reçu est disponible dans ton historique de facturation.',
        'cta' => 'Voir les reçus',
    ],

    'trial_ending' => [
        'subject' => 'Ton essai se termine bientôt',
        'intro' => 'Ton essai gratuit touche à sa fin.',
        'outro' => 'Ajoute un moyen de paiement avant la fin pour que ton abonnement continue sans interruption.',
        'cta' => 'Ajouter un moyen de paiement',
    ],

    'subscription_canceled' => [
        'subject' => 'Ton abonnement a été résilié',
        'intro' => 'Ton abonnement a été résilié et ne sera pas renouvelé.',
        'outro' => 'Tu conserves l’accès jusqu’à la fin de la période payée, indiquée ci-dessous.',
        'cta' => 'Voir l\'offre',
    ],

    'tax_status_changed' => [
        'subject' => 'Ton statut fiscal a changé',
        'intro' => 'Ton statut fiscal est passé de :from à :to. Nous l\'avons établi à partir de nos propres registres — tu ne l\'as pas demandé.',
        'effective' => 'Cela s\'applique à partir du :date.',
        'consequence' => 'À partir de cette date, le montant qui te parvient est plus élevé, car la taxe voyage avec lui. Ce qui te reste ne change pas.',
        'outro' => 'Si cela ne correspond pas à ta situation, dis-le-nous — nous pouvons le corriger.',
    ],

    'reattestation_due' => [
        'subject' => 'Ton attestation fiscale doit être renouvelée',
        'intro_due' => 'Une nouvelle année a commencé, ton attestation fiscale doit donc être renouvelée. Confirme ton statut fiscal d\'ici le :date.',
        'intro_last' => 'Ton attestation fiscale expire le :date et nous n\'avons pas encore reçu de renouvellement.',
        'consequence' => 'Si elle n\'est pas renouvelée d\'ici le :date, les ventes et les versements sont suspendus à partir de ce jour, jusqu\'à ce que tu confirmes à nouveau ton statut.',
        'cta' => 'Confirmer ton statut fiscal',
        'outro' => 'Cela ne prend qu\'un instant, et rien ne change pour toi si ton statut est resté le même.',
    ],

    'seller_data_reminder' => [
        'subject' => 'Complète tes informations de vendeur',
        'intro_first' => 'Il nous manque encore des informations dont nous avons besoin de ta part en tant que vendeur : :fields.',
        'intro_second' => 'Second rappel : il nous manque encore des informations dont nous avons besoin de ta part en tant que vendeur : :fields.',
        'consequence_withhold_payout' => 'Si elles ne sont pas complètes d\'ici le :date, tes versements sont retenus à partir de ce jour, jusqu\'à ce qu\'elles le soient. Rien n\'est perdu : tout ce qui est retenu est versé dès que tes informations sont complètes.',
        'consequence_suspend_sales' => 'Si elles ne sont pas complètes d\'ici le :date, les ventes sont suspendues à partir de ce jour, jusqu\'à ce qu\'elles le soient.',
        'cta' => 'Compléter tes informations',
        'outro' => 'Cela ne prend qu\'un instant.',
        'fields' => [
            'seller_name' => 'ton nom',
            'seller_address' => 'ton adresse',
            'payout_account' => 'ton compte de versement',
            'payout_account_holder' => 'le titulaire de ton compte de versement',
            'seller_tax_identifier' => 'ton numéro d\'identification fiscale',
            'seller_register_number' => 'ton numéro d\'immatriculation',
            'seller_date_of_birth' => 'ta date de naissance',
            'seller_vat_identifier' => 'ton numéro de TVA',
        ],
    ],

    'suspension_warning' => [
        'subject' => 'Action requise : ton accès va être suspendu',
        'intro' => 'Ton compte présente un solde impayé et ton accès sera bientôt suspendu.',
        'late_fee' => 'Des frais de retard de :amount ont été ajoutés au montant dû.',
        'outro' => 'Règle le montant dû pour conserver ton accès.',
        'cta' => 'Régler le montant dû',
    ],

    'card_expiring' => [
        'subject' => 'Ta carte est sur le point d’expirer',
        'intro' => 'La carte enregistrée (:card) expire bientôt.',
        'outro' => 'Mets à jour ton moyen de paiement pour éviter une interruption de ton abonnement.',
        'cta' => 'Mettre à jour la carte',
    ],

    'payment_method_removed' => [
        'subject' => 'Un moyen de paiement a été supprimé',
        'intro' => 'Un moyen de paiement qui pouvait être débité pour ton abonnement a été supprimé de ton compte.',
        'outro' => 'Si ce n’était pas toi, ajoute un nouveau moyen de paiement pour garder ton abonnement actif.',
        'cta' => 'Gérer les moyens de paiement',
    ],

    'quota_warning' => [
        'subject' => 'Tu approches de ta limite de :meter',
        'intro' => 'Tu as utilisé :used sur :included :meter inclus sur cette période.',
        'outro' => 'Recharge ou change d’offre pour continuer sans interruption.',
        'cta' => 'Voir la consommation',
    ],

    'subscription_activated' => [
        'subject' => 'Ton abonnement est actif',
        'intro' => 'Ton offre :tier est maintenant active — tout ce qu’elle comprend est débloqué.',
        'outro' => 'Tu peux consulter ou changer d’offre à tout moment dans tes réglages de facturation.',
        'cta' => 'Voir l\'offre',
    ],

    'payment_action_required' => [
        'subject' => 'Confirme ton paiement pour continuer',
        'intro' => 'Ta banque a besoin que tu confirmes ce paiement avant que ton abonnement puisse continuer.',
        'outro' => 'Confirme-le maintenant pour éviter toute interruption de service.',
        'cta' => 'Confirmer le paiement',
    ],

];
