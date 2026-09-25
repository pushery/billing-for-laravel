<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'No hemos podido procesar tu pago',
        'intro' => 'No hemos podido procesar tu último pago.',
        'outro' => 'Actualiza tus datos de pago para mantener tu suscripción activa.',
        'cta' => 'Actualizar los datos de pago',
    ],

    'payment_succeeded' => [
        'subject' => 'Tu recibo de pago',
        'intro' => 'Gracias: hemos recibido tu pago.',
        'declarations' => 'Tus declaraciones antes de que comenzara la prestación:',
        'outro' => 'Tienes una copia de este recibo en tu historial de facturación.',
        'cta' => 'Ver los recibos',
    ],

    'trial_ending' => [
        'subject' => 'Tu prueba termina pronto',
        'intro' => 'Tu prueba gratuita está llegando a su fin.',
        'outro' => 'Añade un método de pago antes de que termine para que tu suscripción siga sin interrupciones.',
        'cta' => 'Añadir un método de pago',
    ],

    'subscription_canceled' => [
        'subject' => 'Tu suscripción se ha cancelado',
        'intro' => 'Tu suscripción se ha cancelado y no se renovará.',
        'outro' => 'Conservas el acceso hasta el final del periodo pagado, que se indica abajo.',
        'cta' => 'Ver el plan',
    ],

    'tax_status_changed' => [
        'subject' => 'Tu situación fiscal ha cambiado',
        'intro' => 'Tu situación fiscal ha pasado de :from a :to. Lo determinamos a partir de nuestros propios registros: no lo pediste.',
        'effective' => 'Se aplica desde el :date.',
        'consequence' => 'Desde esa fecha el importe que te llega es mayor, porque el impuesto viaja con él. Lo que te queda no cambia.',
        'outro' => 'Si esto no coincide con tu situación, avísanos: podemos corregirlo.',
    ],

    'reattestation_due' => [
        'subject' => 'Tu certificación fiscal debe renovarse',
        'intro_due' => 'Ha empezado un nuevo año, así que tu certificación fiscal debe renovarse. Confirma tu situación fiscal antes del :date.',
        'intro_last' => 'Tu certificación fiscal caduca el :date y todavía no hemos recibido la renovación.',
        'consequence' => 'Si no se renueva antes del :date, las ventas y los pagos se detienen desde ese día hasta que vuelvas a confirmar tu situación.',
        'cta' => 'Confirmar tu situación fiscal',
        'outro' => 'Solo lleva un momento, y si tu situación sigue siendo la misma, para ti no cambia nada.',
    ],

    'seller_data_reminder' => [
        'subject' => 'Completa tus datos de vendedor',
        'intro_first' => 'Aún nos faltan algunos datos que necesitamos de ti como vendedor: :fields.',
        'intro_second' => 'Segundo recordatorio: aún nos faltan algunos datos que necesitamos de ti como vendedor: :fields.',
        'consequence_withhold_payout' => 'Si no están completos el :date, retendremos tus pagos a partir de ese día hasta que lo estén. No se pierde nada: todo lo retenido se paga en cuanto tus datos estén completos.',
        'consequence_suspend_sales' => 'Si no están completos el :date, las ventas se pausan a partir de ese día hasta que lo estén.',
        'cta' => 'Completar tus datos',
        'outro' => 'Solo te llevará un momento.',
        'fields' => [
            'seller_name' => 'tu nombre',
            'seller_address' => 'tu dirección',
            'payout_account' => 'tu cuenta de pago',
            'payout_account_holder' => 'el titular de tu cuenta de pago',
            'seller_tax_identifier' => 'tu número de identificación fiscal',
            'seller_register_number' => 'tu número de registro mercantil',
            'seller_date_of_birth' => 'tu fecha de nacimiento',
            'seller_vat_identifier' => 'tu número de IVA',
        ],
    ],

    'suspension_warning' => [
        'subject' => 'Acción necesaria: tu acceso se suspenderá',
        'intro' => 'Tu cuenta tiene un saldo vencido y tu acceso se suspenderá pronto.',
        'late_fee' => 'Se ha añadido a lo pendiente un recargo por demora de :amount.',
        'outro' => 'Paga lo pendiente para mantener tu acceso.',
        'cta' => 'Saldar lo pendiente',
    ],

    'card_expiring' => [
        'subject' => 'Tu tarjeta está a punto de caducar',
        'intro' => 'La tarjeta guardada (:card) caduca pronto.',
        'outro' => 'Actualiza tu método de pago para evitar una interrupción de tu suscripción.',
        'cta' => 'Actualizar la tarjeta',
    ],

    'payment_method_removed' => [
        'subject' => 'Se eliminó un método de pago',
        'intro' => 'Se eliminó de tu cuenta un método de pago que se podía cobrar para tu suscripción.',
        'outro' => 'Si no fuiste tú, añade un nuevo método de pago para mantener tu suscripción activa.',
        'cta' => 'Gestionar métodos de pago',
    ],

    'quota_warning' => [
        'subject' => 'Estás cerca de tu límite de :meter',
        'intro' => 'Has usado :used de :included :meter incluidos en este periodo.',
        'outro' => 'Recarga o cambia de plan para continuar sin interrupciones.',
        'cta' => 'Ver el consumo',
    ],

    'subscription_activated' => [
        'subject' => 'Tu suscripción está activa',
        'intro' => 'Tu plan :tier ya está activo: todo lo que incluye está habilitado.',
        'outro' => 'Puedes consultar o cambiar tu plan cuando quieras en los ajustes de facturación.',
        'cta' => 'Ver el plan',
    ],

    'payment_action_required' => [
        'subject' => 'Confirma tu pago para continuar',
        'intro' => 'Tu banco necesita que confirmes este pago antes de que tu suscripción pueda continuar.',
        'outro' => 'Confírmalo ahora para evitar cualquier interrupción del servicio.',
        'cta' => 'Confirmar el pago',
    ],

];
