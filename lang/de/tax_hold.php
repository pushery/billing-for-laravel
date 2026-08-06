<?php

declare(strict_types=1);

// Why a merchant can no longer sell or be paid. The event carries a KEY, never a sentence: whether a
// standing blocks is a jurisdiction's rule, and the person reading it may sit in another one.
//
// The lapsed-attestation wording says plainly that the recipient did nothing wrong. They did not — the date
// simply passed — and a notice that reads like an accusation is one they answer defensively instead of
// acting on.

return [

    'attestation_lapsed' => [
        'title' => 'Deine Steuer-Attestierung ist abgelaufen',
        'body' => 'Bis zur Erneuerung kannst du weder verkaufen noch Auszahlungen erhalten. Du hast nichts falsch gemacht — die Attestierung hat schlicht ihr Enddatum erreicht.',
    ],

    'status_recorded' => [
        'title' => 'Dein Steuerstatus muss geklärt werden',
        'body' => 'Bis zur Erneuerung kannst du weder verkaufen noch Auszahlungen erhalten. Du hast nichts falsch gemacht — die Attestierung hat schlicht ihr Enddatum erreicht.',
    ],

    'deadline_approaching' => [
        'title' => 'Dein Steuerstatus wird bald benötigt',
        'body' => 'Ab dem Stichtag können wir ohne deine Angabe weder Verkäufe abwickeln noch auszahlen. Du kannst das jetzt in Ruhe erledigen — es dauert nur einen Moment und verhindert eine Unterbrechung.',
    ],

];
