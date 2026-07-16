<?php

declare(strict_types=1);

return [

    'title' => 'Facturación',

    'overview' => [
        'heading' => 'Resumen de facturación',
        'current_plan' => 'Plan actual: :plan.',
    ],

    'banner' => [
        'past_due' => 'Tu último pago ha fallado. Actualiza tu método de pago para mantener tu suscripción activa.',
        'incomplete' => 'Tu pago necesita confirmación antes de que empiece tu suscripción.',
        'grace' => 'Tu suscripción está cancelada y termina pronto. Reanúdala para mantener tu acceso.',
        'paused' => 'Tu facturación está en pausa, así que tus funciones de pago están suspendidas. Puedes reanudarla cuando quieras.',
        'trial_ending' => 'Tu prueba termina pronto. Elige un plan para mantener tu acceso.',
        'cta' => [
            'recover' => 'Solucionar el pago',
            'confirm' => 'Confirmar pago',
            'resume' => 'Reanudar',
            'upgrade' => 'Elegir un plan',
        ],
    ],

    'manage' => [
        'heading' => 'Cambiar de plan',
        'current' => 'Plan actual: :plan.',
        'swap_to' => 'Cambiar',
        'subscribe' => 'Suscribirte',
        'trial_days' => 'Incluye una prueba gratuita de :days días.',
        'preview' => 'Ver coste',
        'preview_due' => 'A pagar hoy con prorrateo: :amount.',
        'preview_unavailable' => 'No hay estimación disponible para este cambio.',
        'no_options' => 'No hay otros planes disponibles.',
    ],

    'coupon' => [
        'label' => 'Código de cupón',
        'placeholder' => 'Introduce un código',
        'applied' => 'Cupón aplicado.',
        'invalid' => 'Ese código no es válido.',
    ],

    'trial' => [
        'generic' => 'Tu prueba gratuita termina en :days día: elige un plan para mantener tu acceso.|Tu prueba gratuita termina en :days días: elige un plan para mantener tu acceso.',
        'add_pm' => 'Tu prueba termina en :days día: añade un método de pago para que tu plan continúe.|Tu prueba termina en :days días: añade un método de pago para que tu plan continúe.',
        'upgrade' => 'Tu prueba termina en :days día: puedes revisar tu plan cuando quieras.|Tu prueba termina en :days días: puedes revisar tu plan cuando quieras.',
        'usage' => 'Te queda :days día de prueba; el uso de abajo corresponde a tu plan de prueba.|Te quedan :days días de prueba; el uso de abajo corresponde a tu plan de prueba.',
        'cta' => [
            'subscribe' => 'Suscríbete ahora',
            'add_payment_method' => 'Añadir método de pago',
            'upgrade' => 'Ver planes',
        ],
    ],

    'interval' => [
        'day' => 'día',
        'week' => 'semana',
        'month' => 'mes',
        'year' => 'año',
    ],

    'subscription' => [
        'heading' => 'Suscripción',
        'status' => 'Estado',
        'next_invoice' => 'Próxima factura: :amount el :date.',
        'cancel' => 'Cancelar suscripción',
        'resume' => 'Reanudar suscripción',
        'portal' => 'Abrir el portal de facturación',
    ],

    'invoices' => [
        'heading' => 'Facturas',
        'empty' => 'Aún no hay facturas.',
        'date' => 'Fecha',
        'number' => 'Número',
        'amount' => 'Importe',
        'status' => 'Estado',
        'download' => 'Descargar',
    ],

    'invoice_status' => [
        'draft' => 'Borrador',
        'open' => 'Abierta',
        'paid' => 'Pagada',
        'uncollectible' => 'Incobrable',
        'void' => 'Anulada',
        'refunded' => 'Reembolsada',
    ],

    'payment_methods' => [
        'heading' => 'Métodos de pago',
        'add' => 'Añadir método de pago',
        'default' => 'Predeterminado',
        'make_default' => 'Establecer como predeterminado',
        'remove' => 'Eliminar',
        'empty' => 'No hay métodos de pago guardados.',
        'expired' => 'Caducada :date',
        'expiring' => 'Caduca :date',
    ],

    'degraded' => 'Una parte de esta página no se pudo cargar. Inténtalo de nuevo enseguida.',

    'usage' => [
        'unavailable' => 'El uso no está disponible en este momento. Vuelve a intentarlo enseguida.',
        'heading' => 'Uso',
        'prepaid' => 'Saldo prepago: :units :unit',
        'unmetered' => 'Tu plan no tiene límites de uso.',
        'warning' => 'Te estás acercando a tu límite.',
        'over' => 'Has superado tu límite.',
        'over_soft' => 'Has superado tu cuota incluida; el uso adicional se factura.',
    ],

    'usage_history' => [
        'heading' => 'Historial de uso',
        'unavailable' => 'El historial de uso no está disponible ahora mismo. Inténtalo de nuevo en un momento.',
        'empty' => 'Todavía no hay uso registrado.',
        'periods_heading' => 'Periodos anteriores',
        'used' => ':used usados',
        'prepaid_used' => ':units de prepago',
        'topups_heading' => 'Recargas',
        'reversed' => 'anulado',
    ],

    'recovery' => [
        'heading' => 'Recuperación de pago',
        'failed' => 'Tu último pago ha fallado.',
        'current_method' => 'El método de pago guardado es :method.',
        'no_method' => 'No tienes ningún método de pago guardado.',
        'update' => 'Actualizar método de pago',
        'all_good' => 'Nada que recuperar: tus pagos están al día.',
        'incomplete' => 'Tu pago necesita confirmación antes de que empiece tu suscripción.',
        'incomplete_hint' => 'Tu banco te ha pedido que confirmes este pago. Confírmalo para activar tu suscripción.',
        'confirm' => 'Confirmar pago',
    ],

    'reconfirm' => [
        'prompt' => 'Confirma tu contraseña para continuar.',
        'wrong' => 'No coincide. Inténtalo de nuevo.',
        'throttled' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',
    ],

    'danger' => [
        'heading' => 'Zona de peligro',
        'explanation' => 'Si cancelas ahora, la facturación se detiene de inmediato, sin periodo de gracia.',
        'cancel_now' => 'Cancelar la facturación ahora',
        'confirm_question' => 'Esto no se puede deshacer. ¿Cancelar la facturación de inmediato?',
        'confirm_yes' => 'Sí, cancelar ahora',
        'confirm_no' => 'Mantener mi suscripción',
    ],

    'credit' => [
        'balance' => 'Tienes :amount de saldo en tu cuenta.',
        'explanation' => 'Se aplica automáticamente a tu próxima factura.',
    ],

    'state' => [
        'none' => 'Sin suscripción',
        'churned' => 'No suscrito',
        'activating' => 'Activando',
        'generic_trial' => 'Prueba',
        'trialing' => 'En prueba',
        'active' => 'Activa',
        'past_due' => 'Pago fallido',
        'incomplete' => 'Pago incompleto',
        'incomplete_expired' => 'Pago caducado',
        'grace' => 'Se cancela al final del periodo',
        'paused' => 'En pausa',
        'ended' => 'Finalizada',
    ],

    'nav' => [
        'subscription' => 'Suscripción',
        'plan' => 'Plan',
        'payment_methods' => 'Métodos de pago',
        'invoices' => 'Facturas',
        'usage' => 'Uso',
        'usage_history' => 'Historial',
        'recovery' => 'Recuperación',
        'danger' => 'Zona de peligro',
    ],

];
