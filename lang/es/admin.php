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

    'datev' => [
        'heading' => 'Lote contable (DATEV)',
        'intro' => 'Descarga un periodo como lote contable DATEV EXTF: el archivo que importa un asesor fiscal alemán. Es el mismo lote que produce la exportación programada, no se guarda nada en el servidor y, si el periodo no puede contabilizarse como un único lote, la descarga se rechaza en lugar de truncarse.',
        'from' => 'Desde',
        'to' => 'Hasta',
        'submit' => 'Descargar lote',
        'invalid_period' => 'Indica una fecha de inicio y otra de fin, con el fin en la fecha de inicio o posterior.',
        'refused' => 'El lote fue rechazado y no se descargó nada:',
        'unbalanced' => 'Este lote no cuadra y no debe presentarse.',
        'imbalance_figures' => 'El libro auxiliar registra :subledger en deudas con comerciantes; el lote exportado contabiliza :batch en las cuentas de deudas, una diferencia de :difference.',
        'download_anyway' => 'Descargarlo de todos modos',
    ],

    'cancel' => [
        'heading' => 'Cancelar una suscripción',
        'intro' => 'Finaliza de inmediato la suscripción de un titular. El cambio queda registrado en el historial de auditoría a tu nombre.',
        'owner_id' => 'ID del titular',
        'submit' => 'Cancelar suscripción',
        'canceled' => 'Suscripción cancelada.',
        'not_found' => 'No se encontró ningún titular con ese ID.',
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
