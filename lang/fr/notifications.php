<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Ton paiement n’a pas pu être traité',
        'intro' => 'Nous n’avons pas pu traiter ton dernier paiement.',
        'outro' => 'Mets à jour tes informations de paiement pour garder ton abonnement actif.',
    ],

    'payment_succeeded' => [
        'subject' => 'Ton reçu de paiement',
        'intro' => 'Merci — nous avons bien reçu ton paiement.',
        'outro' => 'Une copie de ce reçu est disponible dans ton historique de facturation.',
    ],

    'trial_ending' => [
        'subject' => 'Ton essai se termine bientôt',
        'intro' => 'Ton essai gratuit touche à sa fin.',
        'outro' => 'Ajoute un moyen de paiement avant la fin pour que ton abonnement continue sans interruption.',
    ],

    'subscription_canceled' => [
        'subject' => 'Ton abonnement a été résilié',
        'intro' => 'Ton abonnement a été résilié et ne sera pas renouvelé.',
        'outro' => 'Tu conserves l’accès jusqu’à la fin de la période payée, indiquée ci-dessous.',
    ],

    'suspension_warning' => [
        'subject' => 'Action requise : ton accès va être suspendu',
        'intro' => 'Ton compte présente un solde impayé et ton accès sera bientôt suspendu.',
        'outro' => 'Règle le montant indiqué ci-dessous pour conserver ton accès.',
    ],

    'card_expiring' => [
        'subject' => 'Ta carte est sur le point d’expirer',
        'intro' => 'La carte enregistrée (:card) expire bientôt.',
        'outro' => 'Mets à jour ton moyen de paiement pour éviter une interruption de ton abonnement.',
    ],

    'payment_method_removed' => [
        'subject' => 'Un moyen de paiement a été supprimé',
        'intro' => 'Un moyen de paiement qui pouvait être débité pour ton abonnement a été supprimé de ton compte.',
        'outro' => 'Si ce n’était pas toi, ajoute un nouveau moyen de paiement pour garder ton abonnement actif.',
    ],

    'quota_warning' => [
        'subject' => 'Tu approches de ta limite de :meter',
        'intro' => 'Tu as utilisé :used sur :included :meter inclus sur cette période.',
        'outro' => 'Recharge ou change d’offre pour continuer sans interruption.',
    ],

    'subscription_activated' => [
        'subject' => 'Ton abonnement est actif',
        'intro' => 'Ton offre :tier est maintenant active — tout ce qu’elle comprend est débloqué.',
        'outro' => 'Tu peux consulter ou changer d’offre à tout moment dans tes réglages de facturation.',
    ],

    'payment_action_required' => [
        'subject' => 'Confirme ton paiement pour continuer',
        'intro' => 'Ta banque a besoin que tu confirmes ce paiement avant que ton abonnement puisse continuer.',
        'outro' => 'Confirme-le maintenant pour éviter toute interruption de service.',
    ],

];
