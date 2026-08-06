<?php

declare(strict_types=1);

return [
    // Why a period is what it is. Keys, not statutes: the authority behind each one is a jurisdiction's
    // answer and belongs in its profile, so the core names the KIND of record and nothing more.
    'basis' => [
        'documents' => 'Invoices and settlement documents',
        'books' => 'Books and posting batches',
        'no_obligation' => 'No obligation to keep',
        'delivery_replay' => 'Stored provider deliveries, kept only so a failed effect can be re-driven',
    ],
];
