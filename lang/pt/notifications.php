<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Não foi possível processar o teu pagamento',
        'intro' => 'Não conseguimos processar o teu último pagamento.',
        'outro' => 'Atualiza os teus dados de pagamento para manteres a tua subscrição ativa.',
        'cta' => 'Atualizar os dados de pagamento',
    ],

    'payment_succeeded' => [
        'subject' => 'O teu comprovativo de pagamento',
        'intro' => 'Obrigado — recebemos o teu pagamento.',
        'declarations' => 'As tuas declarações antes de a prestação começar:',
        'outro' => 'Tens uma cópia deste comprovativo no teu histórico de faturação.',
        'cta' => 'Ver os recibos',
    ],

    'trial_ending' => [
        'subject' => 'O teu período de teste termina em breve',
        'intro' => 'O teu período de teste gratuito está a chegar ao fim.',
        'outro' => 'Adiciona um método de pagamento antes de terminar para que a tua subscrição continue sem interrupções.',
        'cta' => 'Adicionar um método de pagamento',
    ],

    'subscription_canceled' => [
        'subject' => 'A tua subscrição foi cancelada',
        'intro' => 'A tua subscrição foi cancelada e não será renovada.',
        'outro' => 'Manténs o acesso até ao fim do período pago, indicado abaixo.',
        'cta' => 'Ver o plano',
    ],

    'tax_status_changed' => [
        'subject' => 'A tua situação fiscal mudou',
        'intro' => 'A tua situação fiscal passou de :from para :to. Determinámos isto a partir dos nossos próprios registos — não foi pedido por ti.',
        'effective' => 'Aplica-se a partir de :date.',
        'consequence' => 'A partir dessa data o montante que te chega é maior, porque o imposto viaja com ele. O que te fica não muda.',
        'outro' => 'Se isto não corresponder à tua situação, diz-nos — podemos corrigir.',
    ],

    'reattestation_due' => [
        'subject' => 'A tua atestação fiscal tem de ser renovada',
        'intro_due' => 'Começou um novo ano, por isso a tua atestação fiscal tem de ser renovada. Confirma a tua situação fiscal até :date.',
        'intro_last' => 'A tua atestação fiscal expira a :date e ainda não recebemos a renovação.',
        'consequence' => 'Se não for renovada até :date, as vendas e os pagamentos param a partir desse dia, até voltares a confirmar a tua situação.',
        'cta' => 'Confirmar a tua situação fiscal',
        'outro' => 'Leva só um momento, e se a tua situação se mantiver igual, nada muda para ti.',
    ],

    'seller_data_reminder' => [
        'subject' => 'Completa os teus dados de vendedor',
        'intro_first' => 'Ainda nos faltam alguns dados de que precisamos de ti como vendedor: :fields.',
        'intro_second' => 'Segundo lembrete: ainda nos faltam alguns dados de que precisamos de ti como vendedor: :fields.',
        'consequence_withhold_payout' => 'Se não estiverem completos até :date, os teus pagamentos ficam retidos a partir desse dia, até estarem. Nada se perde: tudo o que ficar retido é pago assim que os teus dados estiverem completos.',
        'consequence_suspend_sales' => 'Se não estiverem completos até :date, as vendas param a partir desse dia, até estarem.',
        'cta' => 'Completar os teus dados',
        'outro' => 'Leva só um momento.',
        'fields' => [
            'seller_name' => 'o teu nome',
            'seller_address' => 'a tua morada',
            'payout_account' => 'a tua conta de pagamento',
            'payout_account_holder' => 'o titular da tua conta de pagamento',
            'seller_tax_identifier' => 'o teu número de identificação fiscal',
            'seller_register_number' => 'o teu número de registo comercial',
            'seller_date_of_birth' => 'a tua data de nascimento',
            'seller_vat_identifier' => 'o teu número de IVA',
        ],
    ],

    'suspension_warning' => [
        'subject' => 'Ação necessária: o teu acesso vai ser suspenso',
        'intro' => 'A tua conta tem um valor em dívida e o teu acesso vai ser suspenso em breve.',
        'outro' => 'Regulariza o valor indicado abaixo para manteres o teu acesso.',
        'cta' => 'Liquidar o valor em dívida',
    ],

    'card_expiring' => [
        'subject' => 'O teu cartão está prestes a expirar',
        'intro' => 'O cartão guardado (:card) expira em breve.',
        'outro' => 'Atualiza o teu método de pagamento para evitar uma interrupção da tua subscrição.',
        'cta' => 'Atualizar o cartão',
    ],

    'payment_method_removed' => [
        'subject' => 'Um método de pagamento foi removido',
        'intro' => 'Um método de pagamento que podia ser cobrado para a tua subscrição foi removido da tua conta.',
        'outro' => 'Se não foste tu, adiciona um novo método de pagamento para manteres a tua subscrição ativa.',
        'cta' => 'Gerir métodos de pagamento',
    ],

    'quota_warning' => [
        'subject' => 'Estás perto do teu limite de :meter',
        'intro' => 'Usaste :used de :included :meter incluídos neste período.',
        'outro' => 'Recarrega ou muda de plano para continuares sem interrupções.',
        'cta' => 'Ver o consumo',
    ],

    'subscription_activated' => [
        'subject' => 'A tua subscrição está ativa',
        'intro' => 'O teu plano :tier está agora ativo — tudo o que inclui está ligado.',
        'outro' => 'Podes ver ou mudar de plano quando quiseres nas definições de faturação.',
        'cta' => 'Ver o plano',
    ],

    'payment_action_required' => [
        'subject' => 'Confirma o teu pagamento para continuar',
        'intro' => 'O teu banco precisa que confirmes este pagamento antes de a tua subscrição poder continuar.',
        'outro' => 'Confirma-o agora para evitares qualquer interrupção do serviço.',
        'cta' => 'Confirmar o pagamento',
    ],

];
