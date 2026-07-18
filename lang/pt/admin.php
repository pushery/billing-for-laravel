<?php

declare(strict_types=1);

return [

    'title' => 'Administração de faturação',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Métricas',
        'mrr' => 'MRR',
        'active' => 'Subscrições ativas',
        'trials' => 'Em avaliação',
        'dunning' => 'Em cobrança',
        'churned' => 'Canceladas (:days d)',
    ],

    'comp' => [
        'heading' => 'Conceder um plano',
        'intro' => 'Concede um plano a um titular de forma extraordinária. Usa um plano listado em billing.untouchable_tiers para que o próximo webhook do fornecedor não o substitua.',
        'owner_id' => 'ID do titular',
        'tier' => 'Plano',
        'submit' => 'Conceder plano',
        'granted' => 'Plano concedido.',
        'not_found' => 'Nenhum titular encontrado para este ID.',
        'invalid_tier' => 'Esse plano não está configurado em billing.tiers.',
    ],

    'audit' => [
        'heading' => 'Atividade recente',
        'type' => 'Evento',
        'source' => 'Origem',
        'subject' => 'Sujeito',
        'when' => 'Quando',
        'empty' => 'Ainda não há eventos de faturação registados.',
    ],

    'source' => [
        'customer' => 'Cliente',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'Sistema',
    ],

];
