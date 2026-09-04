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

    'datev' => [
        'heading' => 'Lote contabilístico (DATEV)',
        'intro' => 'Descarregue um período como lote contabilístico DATEV EXTF — o ficheiro que um consultor fiscal alemão importa. É o mesmo lote produzido pela exportação agendada, nada fica guardado no servidor e, se o período não puder ser lançado como um único lote, a transferência é recusada em vez de truncada.',
        'from' => 'De',
        'to' => 'Até',
        'submit' => 'Descarregar lote',
        'invalid_period' => 'Indique uma data de início e uma de fim, com o fim igual ou posterior ao início.',
        'refused' => 'O lote foi recusado e nada foi descarregado:',
        'unbalanced' => 'Este lote não fecha e não deve ser entregue.',
        'imbalance_figures' => 'O razão auxiliar regista :subledger em dívidas a comerciantes; o lote exportado lança :batch nas contas de dívidas, uma diferença de :difference.',
        'download_anyway' => 'Transferir mesmo assim',
    ],

    'cancel' => [
        'heading' => 'Cancelar uma subscrição',
        'intro' => 'Termina imediatamente a subscrição de um titular. A alteração fica registada no histórico de auditoria no teu nome.',
        'owner_id' => 'ID do titular',
        'submit' => 'Cancelar subscrição',
        'canceled' => 'Subscrição cancelada.',
        'not_found' => 'Nenhum titular encontrado para esse ID.',
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
