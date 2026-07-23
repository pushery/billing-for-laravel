<?php

declare(strict_types=1);

return [

    'title' => 'Facturation',
    'skip_to_content' => 'Aller au contenu',
    'logout' => 'Se déconnecter',

    'overview' => [
        'heading' => 'Aperçu de la facturation',
        'current_plan' => 'Forfait actuel : :plan.',
    ],

    'banner' => [
        'past_due' => 'Ton dernier paiement a échoué. Mets à jour ton moyen de paiement pour garder ton abonnement actif.',
        'incomplete' => 'Ton paiement doit être confirmé avant que ton abonnement démarre.',
        'grace' => 'Ton abonnement est annulé et se termine bientôt. Reprends-le pour conserver ton accès.',
        'paused' => 'Ta facturation est en pause, tes fonctionnalités payantes sont donc suspendues. Tu peux la reprendre quand tu veux.',
        'trial_ending' => 'Ton essai se termine bientôt. Choisis un forfait pour conserver ton accès.',
        'cta' => [
            'recover' => 'Corriger le paiement',
            'confirm' => 'Confirmer le paiement',
            'resume' => 'Reprendre',
            'upgrade' => 'Choisir un forfait',
        ],
    ],

    'manage' => [
        'heading' => 'Changer de forfait',
        'current' => 'Forfait actuel : :plan.',
        'card_on_file' => 'Carte enregistrée : :brand se terminant par :last4.',
        'addons_heading' => 'Options',
        'addon_buy' => 'Acheter',
        'swap_to' => 'Changer',
        'scheduled_swap' => 'Passe à :plan le :date.',
        'scheduled_swap_cancel' => 'Annuler le changement programmé',
        'subscribe' => 'S’abonner',
        'trial_days' => 'Inclut un essai gratuit de :days jours.',
        'preview' => 'Estimer le coût',
        'preview_due' => 'À payer aujourd’hui avec prorata : :amount.',
        'preview_unavailable' => 'Aucune estimation disponible pour ce changement.',
        'no_options' => 'Aucun autre forfait n’est disponible.',
        'link_out' => [
            'body' => 'La facturation de ce compte est gérée sur le site de notre partenaire de facturation.',
            'action' => 'Gérer la facturation',
        ],
    ],

    'coupon' => [
        'label' => 'Code promo',
        'placeholder' => 'Saisis un code',
        'applied' => 'Code promo appliqué.',
        'invalid' => 'Ce code n’est pas valide.',
    ],

    'trial' => [
        'generic' => 'Ton essai gratuit se termine dans :days jour — choisis un forfait pour conserver ton accès.|Ton essai gratuit se termine dans :days jours — choisis un forfait pour conserver ton accès.',
        'add_pm' => 'Ton essai se termine dans :days jour — ajoute un moyen de paiement pour que ton forfait continue.|Ton essai se termine dans :days jours — ajoute un moyen de paiement pour que ton forfait continue.',
        'upgrade' => 'Ton essai se termine dans :days jour — tu peux revoir ton forfait quand tu veux.|Ton essai se termine dans :days jours — tu peux revoir ton forfait quand tu veux.',
        'usage' => 'Il te reste :days jour d’essai ; la consommation ci-dessous correspond à ton forfait d’essai.|Il te reste :days jours d’essai ; la consommation ci-dessous correspond à ton forfait d’essai.',
        'cta' => [
            'subscribe' => 'S’abonner maintenant',
            'add_payment_method' => 'Ajouter un moyen de paiement',
            'upgrade' => 'Voir les forfaits',
        ],
    ],

    'interval' => [
        'day' => 'jour',
        'week' => 'semaine',
        'month' => 'mois',
        'year' => 'an',
    ],

    'subscription' => [
        'heading' => 'Abonnement',
        'status' => 'Statut',
        'next_invoice' => 'Prochaine facture : :amount le :date.',
        'access_ends' => 'Ton accès se termine le :date.',
        'access_ended' => 'Ton accès s’est terminé le :date.',
        'cancel' => 'Résilier l’abonnement',
        'resume' => 'Reprendre l’abonnement',
        'portal' => 'Ouvrir le portail de facturation',
    ],

    'invoices' => [
        'heading' => 'Factures',
        'empty' => 'Aucune facture pour l’instant.',
        'date' => 'Date',
        'number' => 'Numéro',
        'amount' => 'Montant',
        'status' => 'Statut',
        'download' => 'Télécharger',
        'load_older' => 'Charger les plus anciennes',
    ],

    'invoice_status' => [
        'draft' => 'Brouillon',
        'open' => 'Ouverte',
        'paid' => 'Payée',
        'uncollectible' => 'Irrécouvrable',
        'void' => 'Annulée',
        'refunded' => 'Remboursée',
    ],

    'payment_methods' => [
        'heading' => 'Moyens de paiement',
        'add' => 'Ajouter un moyen de paiement',
        'default' => 'Par défaut',
        'make_default' => 'Définir par défaut',
        'remove' => 'Supprimer',
        'empty' => 'Aucun moyen de paiement enregistré.',
        'expired' => 'Expirée :date',
        'expiring' => 'Expire :date',
        'cannot_remove_last_default' => 'Tu ne peux pas supprimer la carte sur laquelle ton abonnement actif est prélevé. Ajoute d’abord un autre moyen de paiement et définis-le par défaut.',
    ],

    'degraded' => 'Une partie de cette page n’a pas pu se charger. Réessaie dans un instant.',

    'usage' => [
        'unavailable' => 'L’utilisation est momentanément indisponible. Réessaie dans un instant.',
        'heading' => 'Consommation',
        'prepaid' => 'Solde prépayé : :units :unit',
        'unmetered' => 'Ton forfait n’a aucune limite de consommation.',
        'warning' => 'Tu approches de ta limite.',
        'over' => 'Tu as dépassé ta limite.',
        'over_soft' => 'Tu as dépassé ton quota inclus ; la consommation au-delà est facturée.',
        'cta_upgrade' => 'Passe à un forfait supérieur pour augmenter cette limite',
        'cta_topup' => 'Recharge ce quota',
    ],

    'usage_history' => [
        'heading' => 'Historique de consommation',
        'unavailable' => 'L’historique de consommation n’est pas disponible pour le moment. Réessaie dans un instant.',
        'empty' => 'Aucune consommation enregistrée pour l’instant.',
        'periods_heading' => 'Périodes précédentes',
        'used' => ':used utilisés',
        'not_metered' => 'Non mesuré',
        'prepaid_used' => ':units prépayés',
        'topups_heading' => 'Recharges',
        'reversed' => 'annulé',
    ],

    'recovery' => [
        'heading' => 'Régularisation du paiement',
        'failed' => 'Ton dernier paiement a échoué.',
        'current_method' => 'Le moyen de paiement enregistré est :method.',
        'no_method' => 'Tu n’as aucun moyen de paiement enregistré.',
        'update' => 'Mettre à jour le moyen de paiement',
        'all_good' => 'Rien à récupérer — tes paiements sont à jour.',
        'incomplete' => 'Ton paiement doit être confirmé avant que ton abonnement démarre.',
        'incomplete_hint' => 'Ta banque t’a demandé de confirmer ce paiement. Confirme-le pour activer ton abonnement.',
        'confirm' => 'Confirmer le paiement',
    ],

    'reconfirm' => [
        'prompt' => 'Confirme ton mot de passe pour continuer.',
        'wrong' => 'Ça ne correspond pas. Réessaie.',
        'throttled' => 'Trop de tentatives. Réessaie dans :seconds secondes.',
    ],

    'danger' => [
        'heading' => 'Zone sensible',
        'explanation' => 'Résilier maintenant arrête la facturation immédiatement, sans délai de grâce.',
        'cancel_now' => 'Arrêter la facturation maintenant',
        'confirm_question' => 'Cette action est irréversible. Arrêter la facturation immédiatement ?',
        'confirm_yes' => 'Oui, arrêter maintenant',
        'confirm_no' => 'Conserver mon abonnement',
    ],

    'credit' => [
        'balance' => 'Tu as :amount d\'avoir sur ton compte.',
        'explanation' => 'Il est appliqué automatiquement à ta prochaine facture.',
    ],

    'state' => [
        'none' => 'Aucun abonnement',
        'churned' => 'Non abonné',
        'activating' => 'Activation en cours',
        'generic_trial' => 'Essai',
        'trialing' => 'En essai',
        'active' => 'Actif',
        'past_due' => 'Paiement échoué',
        'incomplete' => 'Paiement incomplet',
        'incomplete_expired' => 'Paiement expiré',
        'grace' => 'Se termine à la fin de la période',
        'paused' => 'En pause',
        'ended' => 'Terminé',
    ],

    'nav' => [
        'subscription' => 'Abonnement',
        'plan' => 'Forfait',
        'payment_methods' => 'Moyens de paiement',
        'invoices' => 'Factures',
        'usage' => 'Consommation',
        'usage_history' => 'Historique',
        'recovery' => 'Récupération',
        'danger' => 'Zone sensible',
        'group' => [
            'subscription' => 'Abonnement',
            'billing' => 'Facturation',
            'usage' => 'Utilisation',
            'account' => 'Compte',
        ],
    ],

];
