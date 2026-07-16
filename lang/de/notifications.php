<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Deine Zahlung konnte nicht verarbeitet werden',
        'intro' => 'Wir konnten deine letzte Zahlung nicht verarbeiten.',
        'outro' => 'Bitte aktualisiere deine Zahlungsdaten, damit dein Abo aktiv bleibt.',
    ],

    'payment_succeeded' => [
        'subject' => 'Deine Zahlungsbestätigung',
        'intro' => 'Danke – wir haben deine Zahlung erhalten.',
        'outro' => 'Eine Kopie dieser Bestätigung findest du in deinem Rechnungsverlauf.',
    ],

    'trial_ending' => [
        'subject' => 'Deine Testphase endet bald',
        'intro' => 'Deine kostenlose Testphase neigt sich dem Ende zu.',
        'outro' => 'Hinterlege vorher eine Zahlungsmethode, damit dein Abo nahtlos weiterläuft.',
    ],

    'subscription_canceled' => [
        'subject' => 'Dein Abo wurde gekündigt',
        'intro' => 'Dein Abo wurde gekündigt und verlängert sich nicht.',
        'outro' => 'Bis zum Ende des bezahlten Zeitraums (siehe unten) behältst du deinen Zugang.',
    ],

    'suspension_warning' => [
        'subject' => 'Handlungsbedarf: Dein Zugang wird gesperrt',
        'intro' => 'Auf deinem Konto ist ein offener Betrag fällig und dein Zugang wird bald gesperrt.',
        'outro' => 'Begleiche den unten genannten Betrag, um deinen Zugang zu behalten.',
    ],

    'card_expiring' => [
        'subject' => 'Deine Karte läuft bald ab',
        'intro' => 'Die hinterlegte Karte (:card) läuft bald ab.',
        'outro' => 'Aktualisiere deine Zahlungsmethode, um eine Unterbrechung deines Abos zu vermeiden.',
    ],

    'payment_method_removed' => [
        'subject' => 'Eine Zahlungsmethode wurde entfernt',
        'intro' => 'Eine Zahlungsmethode, die für dein Abo belastet werden konnte, wurde aus deinem Konto entfernt.',
        'outro' => 'Falls das nicht du warst, füge eine neue Zahlungsmethode hinzu, um dein Abo aktiv zu halten.',
    ],

    'quota_warning' => [
        'subject' => 'Du näherst dich deinem :meter-Limit',
        'intro' => 'Du hast in diesem Zeitraum :used von :included inkludierten :meter verbraucht.',
        'outro' => 'Lade auf oder wechsle den Tarif, damit es ohne Unterbrechung weitergeht.',
    ],

    'subscription_activated' => [
        'subject' => 'Dein Abo ist aktiv',
        'intro' => 'Dein :tier-Tarif ist jetzt aktiv — alles, was dazugehört, ist freigeschaltet.',
        'outro' => 'Du kannst deinen Tarif jederzeit in den Abrechnungseinstellungen ansehen oder ändern.',
    ],

    'payment_action_required' => [
        'subject' => 'Bestätige deine Zahlung, um fortzufahren',
        'intro' => 'Deine Bank muss diese Zahlung von dir bestätigt bekommen, bevor dein Abo weiterlaufen kann.',
        'outro' => 'Bestätige sie jetzt, damit dein Dienst nicht unterbrochen wird.',
    ],

];
