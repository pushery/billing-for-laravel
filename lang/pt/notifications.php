<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Não foi possível processar o teu pagamento',
        'intro' => 'Não conseguimos processar o teu último pagamento.',
        'outro' => 'Atualiza os teus dados de pagamento para manteres a tua subscrição ativa.',
    ],

    'payment_succeeded' => [
        'subject' => 'O teu comprovativo de pagamento',
        'intro' => 'Obrigado — recebemos o teu pagamento.',
        'outro' => 'Tens uma cópia deste comprovativo no teu histórico de faturação.',
    ],

    'trial_ending' => [
        'subject' => 'O teu período de teste termina em breve',
        'intro' => 'O teu período de teste gratuito está a chegar ao fim.',
        'outro' => 'Adiciona um método de pagamento antes de terminar para que a tua subscrição continue sem interrupções.',
    ],

    'subscription_canceled' => [
        'subject' => 'A tua subscrição foi cancelada',
        'intro' => 'A tua subscrição foi cancelada e não será renovada.',
        'outro' => 'Manténs o acesso até ao fim do período pago, indicado abaixo.',
    ],

    'suspension_warning' => [
        'subject' => 'Ação necessária: o teu acesso vai ser suspenso',
        'intro' => 'A tua conta tem um valor em dívida e o teu acesso vai ser suspenso em breve.',
        'outro' => 'Regulariza o valor indicado abaixo para manteres o teu acesso.',
    ],

    'card_expiring' => [
        'subject' => 'O teu cartão está prestes a expirar',
        'intro' => 'O cartão guardado (:card) expira em breve.',
        'outro' => 'Atualiza o teu método de pagamento para evitar uma interrupção da tua subscrição.',
    ],

    'payment_method_removed' => [
        'subject' => 'Um método de pagamento foi removido',
        'intro' => 'Um método de pagamento que podia ser cobrado para a tua subscrição foi removido da tua conta.',
        'outro' => 'Se não foste tu, adiciona um novo método de pagamento para manteres a tua subscrição ativa.',
    ],

    'quota_warning' => [
        'subject' => 'Estás perto do teu limite de :meter',
        'intro' => 'Usaste :used de :included :meter incluídos neste período.',
        'outro' => 'Recarrega ou muda de plano para continuares sem interrupções.',
    ],

    'subscription_activated' => [
        'subject' => 'A tua subscrição está ativa',
        'intro' => 'O teu plano :tier está agora ativo — tudo o que inclui está ligado.',
        'outro' => 'Podes ver ou mudar de plano quando quiseres nas definições de faturação.',
    ],

    'payment_action_required' => [
        'subject' => 'Confirma o teu pagamento para continuar',
        'intro' => 'O teu banco precisa que confirmes este pagamento antes de a tua subscrição poder continuar.',
        'outro' => 'Confirma-o agora para evitares qualquer interrupção do serviço.',
    ],

];
