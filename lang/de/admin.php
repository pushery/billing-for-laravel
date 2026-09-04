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

    'datev' => [
        'heading' => 'Buchungsstapel (DATEV)',
        'intro' => 'Lädt einen Zeitraum als DATEV-EXTF-Buchungsstapel herunter — die Datei, die der Steuerberater importiert. Es ist derselbe Stapel wie beim geplanten Export, auf dem Server wird nichts gespeichert, und lässt sich der Zeitraum nicht als ein Stapel buchen, wird der Download verweigert statt gekürzt.',
        'from' => 'Von',
        'to' => 'Bis',
        'submit' => 'Stapel herunterladen',
        'invalid_period' => 'Gib ein Start- und ein Enddatum an; das Ende darf nicht vor dem Start liegen.',
        'refused' => 'Der Stapel wurde verweigert, es wurde nichts heruntergeladen:',
        'unbalanced' => 'Dieser Stapel stimmt nicht ab und darf nicht eingereicht werden.',
        'imbalance_figures' => 'Das Nebenbuch weist :subledger an Verbindlichkeiten gegenüber Händlern aus; der exportierte Stapel bucht :batch auf die Verbindlichkeitskonten, eine Differenz von :difference.',
        'download_anyway' => 'Trotzdem herunterladen',
    ],

    'cancel' => [
        'heading' => 'Abo kündigen',
        'intro' => 'Beendet das Abo eines Owners sofort. Die Änderung wird mit deinem Namen im Audit-Log festgehalten.',
        'owner_id' => 'Owner-ID',
        'submit' => 'Abo kündigen',
        'canceled' => 'Abo gekündigt.',
        'not_found' => 'Kein Owner mit dieser ID gefunden.',
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
