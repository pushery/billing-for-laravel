<?php

declare(strict_types=1);

return [

    'title' => 'Administración de facturación',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Métricas',
        'mrr' => 'MRR',
        'active' => 'Suscripciones activas',
        'trials' => 'En prueba',
        'dunning' => 'En reclamación',
        'churned' => 'Canceladas (:days d)',
    ],

    'comp' => [
        'heading' => 'Conceder un plan',
        'intro' => 'Concede un plan a un titular de forma extraordinaria. Usa un plan incluido en billing.untouchable_tiers para que el siguiente webhook del proveedor no lo sobrescriba.',
        'owner_id' => 'ID del titular',
        'tier' => 'Plan',
        'submit' => 'Conceder plan',
        'granted' => 'Plan concedido.',
        'not_found' => 'No se encontró ningún titular con ese ID.',
        'invalid_tier' => 'Ese plan no está configurado en billing.tiers.',
    ],

    'audit' => [
        'heading' => 'Actividad reciente',
        'type' => 'Evento',
        'source' => 'Origen',
        'subject' => 'Sujeto',
        'when' => 'Cuándo',
        'empty' => 'Aún no se han registrado eventos de facturación.',
    ],

    'source' => [
        'customer' => 'Cliente',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'Sistema',
    ],

];
