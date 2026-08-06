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
        'title' => 'Tu certificación fiscal ha caducado',
        'body' => 'Hasta que se renueve no puedes vender ni recibir pagos. No hiciste nada: la certificación simplemente llegó a su fecha final.',
    ],

    'status_recorded' => [
        'title' => 'Tu situación fiscal necesita aclararse',
        'body' => 'Hasta que se renueve no puedes vender ni recibir pagos. No hiciste nada: la certificación simplemente llegó a su fecha final.',
    ],

    'deadline_approaching' => [
        'title' => 'Pronto necesitaremos tu situación fiscal',
        'body' => 'A partir de la fecha límite no podremos procesar ventas ni realizar pagos sin ella. Puedes resolverlo ahora con calma — lleva un momento y evita una interrupción.',
    ],

];
