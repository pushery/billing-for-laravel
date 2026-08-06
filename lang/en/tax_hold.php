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
        'title' => 'Your tax attestation has expired',
        'body' => 'Until it is renewed you cannot sell or receive payouts. Nothing you did caused this — the attestation simply reached its end date.',
    ],

    'status_recorded' => [
        'title' => 'Your tax standing needs clarifying',
        'body' => 'Until it is renewed you cannot sell or receive payouts. Nothing you did caused this — the attestation simply reached its end date.',
    ],

    'deadline_approaching' => [
        'title' => 'Your tax standing will be needed soon',
        'body' => 'From the cutoff date we cannot process sales or pay out without it. You can settle this now, at your own pace — it takes a moment and prevents an interruption.',
    ],

];
