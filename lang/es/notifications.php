<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'No hemos podido procesar tu pago',
        'intro' => 'No hemos podido procesar tu último pago.',
        'outro' => 'Actualiza tus datos de pago para mantener tu suscripción activa.',
    ],

    'payment_succeeded' => [
        'subject' => 'Tu recibo de pago',
        'intro' => 'Gracias: hemos recibido tu pago.',
        'outro' => 'Tienes una copia de este recibo en tu historial de facturación.',
    ],

    'trial_ending' => [
        'subject' => 'Tu prueba termina pronto',
        'intro' => 'Tu prueba gratuita está llegando a su fin.',
        'outro' => 'Añade un método de pago antes de que termine para que tu suscripción siga sin interrupciones.',
    ],

    'subscription_canceled' => [
        'subject' => 'Tu suscripción se ha cancelado',
        'intro' => 'Tu suscripción se ha cancelado y no se renovará.',
        'outro' => 'Conservas el acceso hasta el final del periodo pagado, que se indica abajo.',
    ],

    'suspension_warning' => [
        'subject' => 'Acción necesaria: tu acceso se suspenderá',
        'intro' => 'Tu cuenta tiene un saldo vencido y tu acceso se suspenderá pronto.',
        'outro' => 'Paga el importe indicado abajo para mantener tu acceso.',
    ],

    'card_expiring' => [
        'subject' => 'Tu tarjeta está a punto de caducar',
        'intro' => 'La tarjeta guardada (:card) caduca pronto.',
        'outro' => 'Actualiza tu método de pago para evitar una interrupción de tu suscripción.',
    ],

    'payment_method_removed' => [
        'subject' => 'Se eliminó un método de pago',
        'intro' => 'Se eliminó de tu cuenta un método de pago que se podía cobrar para tu suscripción.',
        'outro' => 'Si no fuiste tú, añade un nuevo método de pago para mantener tu suscripción activa.',
    ],

    'quota_warning' => [
        'subject' => 'Estás cerca de tu límite de :meter',
        'intro' => 'Has usado :used de :included :meter incluidos en este periodo.',
        'outro' => 'Recarga o cambia de plan para continuar sin interrupciones.',
    ],

    'subscription_activated' => [
        'subject' => 'Tu suscripción está activa',
        'intro' => 'Tu plan :tier ya está activo: todo lo que incluye está habilitado.',
        'outro' => 'Puedes consultar o cambiar tu plan cuando quieras en los ajustes de facturación.',
    ],

    'payment_action_required' => [
        'subject' => 'Confirma tu pago para continuar',
        'intro' => 'Tu banco necesita que confirmes este pago antes de que tu suscripción pueda continuar.',
        'outro' => 'Confírmalo ahora para evitar cualquier interrupción del servicio.',
    ],

];
