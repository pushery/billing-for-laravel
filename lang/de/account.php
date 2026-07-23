<?php

declare(strict_types=1);

return [

    'title' => 'Abrechnung',
    'skip_to_content' => 'Zum Inhalt springen',
    'logout' => 'Abmelden',

    'overview' => [
        'heading' => 'Abrechnungsübersicht',
        'current_plan' => 'Aktueller Tarif: :plan.',
    ],

    'banner' => [
        'past_due' => 'Deine letzte Zahlung ist fehlgeschlagen. Aktualisiere deine Zahlungsmethode, damit dein Abo aktiv bleibt.',
        'incomplete' => 'Deine Zahlung muss noch bestätigt werden, bevor dein Abo startet.',
        'grace' => 'Dein Abo ist gekündigt und endet bald. Setze es fort, um deinen Zugang zu behalten.',
        'paused' => 'Deine Abrechnung ist pausiert, deine bezahlten Funktionen ruhen so lange. Du kannst jederzeit fortsetzen.',
        'trial_ending' => 'Deine Testphase endet bald. Wähle einen Tarif, um deinen Zugang zu behalten.',
        'cta' => [
            'recover' => 'Zahlung beheben',
            'confirm' => 'Zahlung bestätigen',
            'resume' => 'Fortsetzen',
            'upgrade' => 'Tarif wählen',
        ],
    ],

    'manage' => [
        'heading' => 'Tarif ändern',
        'current' => 'Aktueller Tarif: :plan.',
        'card_on_file' => 'Hinterlegte Karte: :brand endend auf :last4.',
        'addons_heading' => 'Add-ons',
        'addon_buy' => 'Kaufen',
        'swap_to' => 'Wechseln',
        'scheduled_swap' => 'Wechselt am :date zu :plan.',
        'scheduled_swap_cancel' => 'Geplanten Wechsel abbrechen',
        'subscribe' => 'Abonnieren',
        'trial_days' => 'Inklusive :days Tage kostenlos testen.',
        'preview' => 'Kosten vorschauen',
        'preview_due' => 'Heute fällig mit Anrechnung: :amount.',
        'preview_unavailable' => 'Für diese Änderung ist keine Schätzung verfügbar.',
        'no_options' => 'Keine weiteren Tarife verfügbar.',
        'link_out' => [
            'body' => 'Die Abrechnung für dieses Konto wird auf der Seite unseres Abrechnungspartners verwaltet.',
            'action' => 'Abrechnung verwalten',
        ],
    ],

    'coupon' => [
        'label' => 'Gutscheincode',
        'placeholder' => 'Code eingeben',
        'applied' => 'Gutschein angewendet.',
        'invalid' => 'Dieser Code ist ungültig.',
    ],

    'trial' => [
        'generic' => 'Deine kostenlose Testphase endet in :days Tag — wähle einen Tarif, um deinen Zugang zu behalten.|Deine kostenlose Testphase endet in :days Tagen — wähle einen Tarif, um deinen Zugang zu behalten.',
        'add_pm' => 'Deine Testphase endet in :days Tag — füge eine Zahlungsmethode hinzu, damit dein Tarif weiterläuft.|Deine Testphase endet in :days Tagen — füge eine Zahlungsmethode hinzu, damit dein Tarif weiterläuft.',
        'upgrade' => 'Deine Testphase endet in :days Tag — du kannst deinen Tarif jederzeit anpassen.|Deine Testphase endet in :days Tagen — du kannst deinen Tarif jederzeit anpassen.',
        'usage' => 'Du hast noch :days Tag in deiner Testphase; die Nutzung unten entspricht deinem Test-Tarif.|Du hast noch :days Tage in deiner Testphase; die Nutzung unten entspricht deinem Test-Tarif.',
        'cta' => [
            'subscribe' => 'Jetzt abonnieren',
            'add_payment_method' => 'Zahlungsmethode hinzufügen',
            'upgrade' => 'Tarife ansehen',
        ],
    ],

    'interval' => [
        'day' => 'Tag',
        'week' => 'Woche',
        'month' => 'Monat',
        'year' => 'Jahr',
    ],

    'cancel_survey' => [
        'prompt' => 'Grund fürs Kündigen (optional)',
        'no_reason' => 'Keine Angabe',
        'detail_label' => 'Erzähl uns mehr',
        'detail_placeholder' => 'Was hat dich zum Kündigen bewogen?',
        'detail_required' => 'Bitte gib bei „Sonstiges“ ein Detail an.',
        'reason' => [
            'too_expensive' => 'Zu teuer',
            'missing_features' => 'Mir fehlen Funktionen',
            'not_using_enough' => 'Ich nutze es zu wenig',
            'switched_provider' => 'Ich bin zu einem anderen Anbieter gewechselt',
            'technical_issues' => 'Technische Probleme',
            'no_longer_needed' => 'Ich brauche es nicht mehr',
            'other' => 'Sonstiges',
        ],
    ],
    'subscription' => [
        'heading' => 'Abo',
        'status' => 'Status',
        'next_invoice' => 'Nächste Rechnung: :amount am :date.',
        'access_ends' => 'Dein Zugang endet am :date.',
        'access_ended' => 'Dein Zugang endete am :date.',
        'cancel' => 'Abo kündigen',
        'resume' => 'Abo fortsetzen',
        'portal' => 'Zahlungsportal öffnen',
    ],

    'invoices' => [
        'heading' => 'Rechnungen',
        'empty' => 'Noch keine Rechnungen.',
        'date' => 'Datum',
        'number' => 'Nummer',
        'amount' => 'Betrag',
        'status' => 'Status',
        'download' => 'Herunterladen',
        'load_older' => 'Ältere laden',
    ],

    'invoice_status' => [
        'draft' => 'Entwurf',
        'open' => 'Offen',
        'paid' => 'Bezahlt',
        'uncollectible' => 'Uneinbringlich',
        'void' => 'Storniert',
        'refunded' => 'Erstattet',
    ],

    'payment_methods' => [
        'heading' => 'Zahlungsmethoden',
        'add' => 'Zahlungsmethode hinzufügen',
        'default' => 'Standard',
        'make_default' => 'Als Standard',
        'remove' => 'Entfernen',
        'empty' => 'Keine Zahlungsmethoden hinterlegt.',
        'expired' => 'Abgelaufen :date',
        'expiring' => 'Läuft ab :date',
        'cannot_remove_last_default' => 'Du kannst die Karte, über die dein aktives Abo abgerechnet wird, nicht entfernen. Füge zuerst eine andere Zahlungsmethode hinzu und lege sie als Standard fest.',
    ],

    'degraded' => 'Ein Teil dieser Seite konnte nicht geladen werden. Bitte versuche es gleich noch einmal.',

    'usage' => [
        'unavailable' => 'Die Nutzungsübersicht ist gerade nicht verfügbar. Bitte versuche es in einem Moment noch einmal.',
        'heading' => 'Nutzung',
        'prepaid' => 'Vorausbezahltes Guthaben: :units :unit',
        'unmetered' => 'Dein Tarif hat keine gemessenen Limits.',
        'warning' => 'Du näherst dich deinem Limit.',
        'over' => 'Du hast dein Limit überschritten.',
        'over_soft' => 'Du bist über deinem inklusiven Kontingent; darüber hinausgehende Nutzung wird berechnet.',
        'cta_upgrade' => 'Upgrade, um dieses Limit anzuheben',
        'cta_topup' => 'Guthaben aufladen',
    ],

    'usage_history' => [
        'heading' => 'Nutzungsverlauf',
        'unavailable' => 'Der Nutzungsverlauf ist gerade nicht verfügbar. Bitte versuche es in einem Moment noch einmal.',
        'empty' => 'Noch keine Nutzung erfasst.',
        'periods_heading' => 'Vergangene Perioden',
        'used' => ':used genutzt',
        'not_metered' => 'Nicht gemessen',
        'prepaid_used' => ':units vorausbezahlt',
        'topups_heading' => 'Aufladungen',
        'reversed' => 'storniert',
    ],

    'recovery' => [
        'heading' => 'Zahlungswiederherstellung',
        'failed' => 'Deine letzte Zahlung ist fehlgeschlagen.',
        'current_method' => 'Hinterlegte Zahlungsmethode: :method.',
        'no_method' => 'Du hast keine Zahlungsmethode hinterlegt.',
        'update' => 'Zahlungsmethode aktualisieren',
        'all_good' => 'Nichts wiederherzustellen – deine Zahlungen sind aktuell.',
        'incomplete' => 'Deine Zahlung muss bestätigt werden, bevor dein Abo startet.',
        'incomplete_hint' => 'Deine Bank hat dich um Bestätigung dieser Zahlung gebeten. Bestätige sie, um dein Abo zu aktivieren.',
        'confirm' => 'Zahlung bestätigen',
    ],

    'reconfirm' => [
        'prompt' => 'Bestätige dein Passwort, um fortzufahren.',
        'wrong' => 'Das hat nicht gepasst. Bitte versuche es erneut.',
        'throttled' => 'Zu viele Versuche. Versuche es in :seconds Sekunden erneut.',
    ],

    'danger' => [
        'heading' => 'Gefahrenzone',
        'explanation' => 'Wenn du jetzt kündigst, endet die Abrechnung sofort – ohne Übergangsfrist.',
        'cancel_now' => 'Abrechnung sofort beenden',
        'confirm_question' => 'Das lässt sich nicht rückgängig machen. Abrechnung sofort beenden?',
        'confirm_yes' => 'Ja, sofort beenden',
        'confirm_no' => 'Abo behalten',
    ],

    'credit' => [
        'balance' => 'Du hast :amount Guthaben auf deinem Konto.',
        'explanation' => 'Es wird automatisch mit deiner nächsten Rechnung verrechnet.',
    ],

    'state' => [
        'none' => 'Kein Abo',
        'churned' => 'Nicht abonniert',
        'activating' => 'Wird aktiviert',
        'generic_trial' => 'Testphase',
        'trialing' => 'In der Testphase',
        'active' => 'Aktiv',
        'past_due' => 'Zahlung fehlgeschlagen',
        'incomplete' => 'Zahlung unvollständig',
        'incomplete_expired' => 'Zahlung abgelaufen',
        'grace' => 'Endet zum Periodenende',
        'paused' => 'Pausiert',
        'ended' => 'Beendet',
    ],

    'nav' => [
        'subscription' => 'Abo',
        'plan' => 'Tarif',
        'payment_methods' => 'Zahlungsmethoden',
        'invoices' => 'Rechnungen',
        'usage' => 'Nutzung',
        'usage_history' => 'Verlauf',
        'recovery' => 'Wiederherstellung',
        'danger' => 'Gefahrenzone',
        'group' => [
            'subscription' => 'Abonnement',
            'billing' => 'Abrechnung',
            'usage' => 'Nutzung',
            'account' => 'Konto',
        ],
    ],

];
