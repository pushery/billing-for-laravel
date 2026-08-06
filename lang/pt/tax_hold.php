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
        'title' => 'A tua atestação fiscal expirou',
        'body' => 'Até ser renovada não podes vender nem receber pagamentos. Não fizeste nada — a atestação chegou simplesmente à sua data final.',
    ],

    'status_recorded' => [
        'title' => 'A tua situação fiscal precisa de ser esclarecida',
        'body' => 'Até ser renovada não podes vender nem receber pagamentos. Não fizeste nada — a atestação chegou simplesmente à sua data final.',
    ],

    'deadline_approaching' => [
        'title' => 'Em breve precisaremos da tua situação fiscal',
        'body' => 'A partir da data-limite não poderemos processar vendas nem efetuar pagamentos sem ela. Podes tratar disso agora com calma — demora um momento e evita uma interrupção.',
    ],

];
