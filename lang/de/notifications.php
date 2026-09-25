<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Deine Zahlung konnte nicht verarbeitet werden',
        'intro' => 'Wir konnten deine letzte Zahlung nicht verarbeiten.',
        'outro' => 'Bitte aktualisiere deine Zahlungsdaten, damit dein Abo aktiv bleibt.',
        'cta' => 'Zahlungsdaten aktualisieren',
    ],

    'payment_succeeded' => [
        'subject' => 'Deine Zahlungsbestätigung',
        'intro' => 'Danke – wir haben deine Zahlung erhalten.',
        'declarations' => 'Deine Erklärungen vor Beginn der Bereitstellung:',
        'outro' => 'Eine Kopie dieser Bestätigung findest du in deinem Rechnungsverlauf.',
        'cta' => 'Belege ansehen',
    ],

    'trial_ending' => [
        'subject' => 'Deine Testphase endet bald',
        'intro' => 'Deine kostenlose Testphase neigt sich dem Ende zu.',
        'outro' => 'Hinterlege vorher eine Zahlungsmethode, damit dein Abo nahtlos weiterläuft.',
        'cta' => 'Zahlungsmethode hinterlegen',
    ],

    'subscription_canceled' => [
        'subject' => 'Dein Abo wurde gekündigt',
        'intro' => 'Dein Abo wurde gekündigt und verlängert sich nicht.',
        'outro' => 'Bis zum Ende des bezahlten Zeitraums (siehe unten) behältst du deinen Zugang.',
        'cta' => 'Tarif ansehen',
    ],

    'tax_status_changed' => [
        'subject' => 'Dein Steuerstatus hat sich geändert',
        'intro' => 'Dein Steuerstatus hat sich von :from auf :to geändert. Wir haben das aus unseren eigenen Aufzeichnungen abgeleitet — du hast es nicht beantragt.',
        'effective' => 'Es gilt ab :date.',
        'consequence' => 'Ab diesem Datum ist der Betrag, der bei dir ankommt, höher, weil die Steuer mitläuft. Was dir davon bleibt, ändert sich nicht.',
        'outro' => 'Wenn das nicht zu deiner Lage passt, sag uns Bescheid — wir korrigieren es.',
    ],

    'reattestation_due' => [
        'subject' => 'Deine Steuer-Attestierung muss erneuert werden',
        'intro_due' => 'Ein neues Jahr hat begonnen, deshalb muss deine Steuer-Attestierung erneuert werden. Bitte bestätige deinen Steuerstatus bis zum :date.',
        'intro_last' => 'Deine Steuer-Attestierung läuft am :date ab, und wir haben noch keine Erneuerung erhalten.',
        'consequence' => 'Ist sie bis zum :date nicht erneuert, ruhen Verkäufe und Auszahlungen ab diesem Tag, bis du deinen Status wieder bestätigst.',
        'cta' => 'Steuerstatus bestätigen',
        'outro' => 'Das dauert nur einen Moment, und wenn dein Status gleich geblieben ist, ändert sich für dich nichts.',
    ],

    'seller_data_reminder' => [
        'subject' => 'Bitte vervollständige deine Verkäuferangaben',
        'intro_first' => 'Für deine Verkäufe fehlen uns noch Angaben von dir: :fields.',
        'intro_second' => 'Zweite Erinnerung: Für deine Verkäufe fehlen uns noch Angaben von dir: :fields.',
        'consequence_withhold_payout' => 'Sind sie bis zum :date nicht vollständig, halten wir deine Auszahlungen ab diesem Tag zurück, bis sie es sind. Verloren geht dabei nichts: Alles Zurückgehaltene wird ausgezahlt, sobald deine Angaben vollständig sind.',
        'consequence_suspend_sales' => 'Sind sie bis zum :date nicht vollständig, ruhen Verkäufe ab diesem Tag, bis sie es sind.',
        'cta' => 'Angaben vervollständigen',
        'outro' => 'Das dauert nur einen Moment.',
        'fields' => [
            'seller_name' => 'dein Name',
            'seller_address' => 'deine Anschrift',
            'payout_account' => 'dein Auszahlungskonto',
            'payout_account_holder' => 'der Inhaber deines Auszahlungskontos',
            'seller_tax_identifier' => 'deine Steuer-Identifikationsnummer',
            'seller_register_number' => 'deine Handelsregisternummer',
            'seller_date_of_birth' => 'dein Geburtsdatum',
            'seller_vat_identifier' => 'deine Umsatzsteuer-Identifikationsnummer',
        ],
    ],

    'suspension_warning' => [
        'subject' => 'Handlungsbedarf: Dein Zugang wird gesperrt',
        'intro' => 'Auf deinem Konto ist ein offener Betrag fällig und dein Zugang wird bald gesperrt.',
        'late_fee' => 'Zum offenen Betrag kommt eine Mahngebühr von :amount hinzu.',
        'outro' => 'Begleiche den offenen Betrag, um deinen Zugang zu behalten.',
        'cta' => 'Offenen Betrag begleichen',
    ],

    'card_expiring' => [
        'subject' => 'Deine Karte läuft bald ab',
        'intro' => 'Die hinterlegte Karte (:card) läuft bald ab.',
        'outro' => 'Aktualisiere deine Zahlungsmethode, um eine Unterbrechung deines Abos zu vermeiden.',
        'cta' => 'Karte aktualisieren',
    ],

    'payment_method_removed' => [
        'subject' => 'Eine Zahlungsmethode wurde entfernt',
        'intro' => 'Eine Zahlungsmethode, die für dein Abo belastet werden konnte, wurde aus deinem Konto entfernt.',
        'outro' => 'Falls das nicht du warst, füge eine neue Zahlungsmethode hinzu, um dein Abo aktiv zu halten.',
        'cta' => 'Zahlungsmethoden verwalten',
    ],

    'quota_warning' => [
        'subject' => 'Du näherst dich deinem :meter-Limit',
        'intro' => 'Du hast in diesem Zeitraum :used von :included inkludierten :meter verbraucht.',
        'outro' => 'Lade auf oder wechsle den Tarif, damit es ohne Unterbrechung weitergeht.',
        'cta' => 'Verbrauch ansehen',
    ],

    'subscription_activated' => [
        'subject' => 'Dein Abo ist aktiv',
        'intro' => 'Dein :tier-Tarif ist jetzt aktiv — alles, was dazugehört, ist freigeschaltet.',
        'outro' => 'Du kannst deinen Tarif jederzeit in den Abrechnungseinstellungen ansehen oder ändern.',
        'cta' => 'Tarif ansehen',
    ],

    'payment_action_required' => [
        'subject' => 'Bestätige deine Zahlung, um fortzufahren',
        'intro' => 'Deine Bank muss diese Zahlung von dir bestätigt bekommen, bevor dein Abo weiterlaufen kann.',
        'outro' => 'Bestätige sie jetzt, damit dein Dienst nicht unterbrochen wird.',
        'cta' => 'Zahlung bestätigen',
    ],

];
