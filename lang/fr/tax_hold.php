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
        'title' => 'Ton attestation fiscale a expiré',
        'body' => "Jusqu'à son renouvellement, tu ne peux ni vendre ni recevoir de versements. Tu n'y es pour rien : l'attestation a simplement atteint sa date de fin.",
    ],

    'status_recorded' => [
        'title' => 'Ta situation fiscale doit être clarifiée',
        'body' => "Jusqu'à son renouvellement, tu ne peux ni vendre ni recevoir de versements. Tu n'y es pour rien : l'attestation a simplement atteint sa date de fin.",
    ],

    'deadline_approaching' => [
        'title' => 'Ton statut fiscal sera bientôt nécessaire',
        'body' => 'À partir de la date limite, nous ne pourrons ni traiter de ventes ni te verser de paiements sans lui. Tu peux le régler dès maintenant, tranquillement — cela prend un instant et évite une interruption.',
    ],

];
