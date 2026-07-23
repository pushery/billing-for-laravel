<?php

declare(strict_types=1);

return [

    'title' => 'Faturação',
    'skip_to_content' => 'Ir para o conteúdo',
    'logout' => 'Terminar sessão',

    'overview' => [
        'heading' => 'Resumo da faturação',
        'current_plan' => 'Plano atual: :plan.',
    ],

    'banner' => [
        'past_due' => 'O teu último pagamento falhou. Atualiza o teu método de pagamento para manteres a tua subscrição ativa.',
        'incomplete' => 'O teu pagamento precisa de confirmação antes de a tua subscrição começar.',
        'grace' => 'A tua subscrição foi cancelada e termina em breve. Retoma-a para manteres o teu acesso.',
        'paused' => 'A tua faturação está em pausa, por isso as funcionalidades pagas estão suspensas. Podes retomá-la quando quiseres.',
        'trial_ending' => 'O teu período de teste termina em breve. Escolhe um plano para manteres o teu acesso.',
        'cta' => [
            'recover' => 'Corrigir pagamento',
            'confirm' => 'Confirmar pagamento',
            'resume' => 'Retomar',
            'upgrade' => 'Escolher um plano',
        ],
    ],

    'manage' => [
        'heading' => 'Mudar de plano',
        'current' => 'Plano atual: :plan.',
        'card_on_file' => 'Cartão guardado: :brand terminado em :last4.',
        'addons_heading' => 'Complementos',
        'addon_buy' => 'Comprar',
        'swap_to' => 'Mudar',
        'scheduled_swap' => 'Muda para :plan em :date.',
        'scheduled_swap_cancel' => 'Cancelar a mudança agendada',
        'subscribe' => 'Subscrever',
        'trial_days' => 'Inclui um período de teste gratuito de :days dias.',
        'preview' => 'Ver custo',
        'preview_due' => 'A pagar hoje, com valor proporcional: :amount.',
        'preview_unavailable' => 'Não há estimativa disponível para esta alteração.',
        'no_options' => 'Não há outros planos disponíveis.',
        'link_out' => [
            'body' => 'A faturação desta conta é gerida no site do nosso parceiro de faturação.',
            'action' => 'Gerir faturação',
        ],
    ],

    'coupon' => [
        'label' => 'Código de cupão',
        'placeholder' => 'Introduz um código',
        'applied' => 'Cupão aplicado.',
        'invalid' => 'Esse código não é válido.',
    ],

    'trial' => [
        'generic' => 'O teu período de teste gratuito termina em :days dia — escolhe um plano para manteres o teu acesso.|O teu período de teste gratuito termina em :days dias — escolhe um plano para manteres o teu acesso.',
        'add_pm' => 'O teu período de teste termina em :days dia — adiciona um método de pagamento para que o teu plano continue.|O teu período de teste termina em :days dias — adiciona um método de pagamento para que o teu plano continue.',
        'upgrade' => 'O teu período de teste termina em :days dia — podes rever o teu plano quando quiseres.|O teu período de teste termina em :days dias — podes rever o teu plano quando quiseres.',
        'usage' => 'Resta-te :days dia de teste; a utilização abaixo é a do teu plano de teste.|Restam-te :days dias de teste; a utilização abaixo é a do teu plano de teste.',
        'cta' => [
            'subscribe' => 'Subscrever agora',
            'add_payment_method' => 'Adicionar método de pagamento',
            'upgrade' => 'Ver planos',
        ],
    ],

    'interval' => [
        'day' => 'dia',
        'week' => 'semana',
        'month' => 'mês',
        'year' => 'ano',
    ],

    'cancel_survey' => [
        'prompt' => 'Motivo do cancelamento (opcional)',
        'no_reason' => 'Prefiro não dizer',
        'detail_label' => 'Conta-nos mais',
        'detail_placeholder' => 'O que te levou a cancelar?',
        'detail_required' => 'Adiciona um detalhe para «Outro».',
        'reason' => [
            'too_expensive' => 'É demasiado caro',
            'missing_features' => 'Faltam funcionalidades de que preciso',
            'not_using_enough' => 'Não o uso o suficiente',
            'switched_provider' => 'Mudei para outro fornecedor',
            'technical_issues' => 'Problemas técnicos',
            'no_longer_needed' => 'Já não preciso dele',
            'other' => 'Outro',
        ],
    ],
    'subscription' => [
        'heading' => 'Subscrição',
        'status' => 'Estado',
        'next_invoice' => 'Próxima fatura: :amount a :date.',
        'access_ends' => 'O teu acesso termina a :date.',
        'access_ended' => 'O teu acesso terminou a :date.',
        'cancel' => 'Cancelar subscrição',
        'resume' => 'Retomar subscrição',
        'portal' => 'Abrir o portal de faturação',
    ],

    'invoices' => [
        'heading' => 'Faturas',
        'empty' => 'Ainda não há faturas.',
        'date' => 'Data',
        'number' => 'Número',
        'amount' => 'Valor',
        'status' => 'Estado',
        'download' => 'Transferir',
        'load_older' => 'Carregar mais antigas',
    ],

    'invoice_status' => [
        'draft' => 'Rascunho',
        'open' => 'Em aberto',
        'paid' => 'Paga',
        'uncollectible' => 'Incobrável',
        'void' => 'Anulada',
        'refunded' => 'Reembolsada',
    ],

    'payment_methods' => [
        'heading' => 'Métodos de pagamento',
        'add' => 'Adicionar método de pagamento',
        'default' => 'Predefinido',
        'make_default' => 'Tornar predefinido',
        'remove' => 'Remover',
        'empty' => 'Não há métodos de pagamento guardados.',
        'expired' => 'Expirou :date',
        'expiring' => 'Expira :date',
        'cannot_remove_last_default' => 'Não podes remover o cartão no qual a tua subscrição ativa é cobrada. Adiciona primeiro outro método de pagamento e define-o como predefinido.',
    ],

    'degraded' => 'Parte desta página não pôde ser carregada. Tenta novamente daqui a pouco.',

    'usage' => [
        'unavailable' => 'A utilização está temporariamente indisponível. Tenta novamente daqui a pouco.',
        'heading' => 'Utilização',
        'prepaid' => 'Saldo pré-pago: :units :unit',
        'unmetered' => 'O teu plano não tem limites de utilização.',
        'warning' => 'Estás a aproximar-te do teu limite.',
        'over' => 'Excedeste o teu limite.',
        'over_soft' => 'Excedeste a tua quota incluída; a utilização acima é faturada.',
        'cta_upgrade' => 'Faz upgrade para aumentar este limite',
        'cta_topup' => 'Recarrega esta quota',
    ],

    'usage_history' => [
        'heading' => 'Histórico de utilização',
        'unavailable' => 'O histórico de utilização não está disponível de momento. Tenta novamente daqui a pouco.',
        'empty' => 'Ainda não há utilização registada.',
        'periods_heading' => 'Períodos anteriores',
        'used' => ':used usados',
        'not_metered' => 'Não medido',
        'prepaid_used' => ':units pré-pagos',
        'topups_heading' => 'Recargas',
        'reversed' => 'anulado',
    ],

    'recovery' => [
        'heading' => 'Recuperação de pagamento',
        'failed' => 'O teu último pagamento falhou.',
        'current_method' => 'O método de pagamento guardado é :method.',
        'no_method' => 'Não tens nenhum método de pagamento guardado.',
        'update' => 'Atualizar método de pagamento',
        'all_good' => 'Nada a recuperar — os teus pagamentos estão em dia.',
        'incomplete' => 'O teu pagamento precisa de confirmação antes de a tua subscrição começar.',
        'incomplete_hint' => 'O teu banco pediu-te para confirmares este pagamento. Confirma-o para ativares a tua subscrição.',
        'confirm' => 'Confirmar pagamento',
    ],

    'reconfirm' => [
        'prompt' => 'Confirma a tua palavra-passe para continuar.',
        'wrong' => 'Não corresponde. Tenta novamente.',
        'throttled' => 'Demasiadas tentativas. Tenta novamente dentro de :seconds segundos.',
    ],

    'danger' => [
        'heading' => 'Zona de perigo',
        'explanation' => 'Cancelar agora interrompe a faturação de imediato, sem período de tolerância.',
        'cancel_now' => 'Interromper a faturação agora',
        'confirm_question' => 'Isto não pode ser desfeito. Interromper a faturação de imediato?',
        'confirm_yes' => 'Sim, interromper agora',
        'confirm_no' => 'Manter a minha subscrição',
    ],

    'credit' => [
        'balance' => 'Tens :amount de saldo na tua conta.',
        'explanation' => 'É aplicado automaticamente à tua próxima fatura.',
    ],

    'state' => [
        'none' => 'Sem subscrição',
        'churned' => 'Não subscrito',
        'activating' => 'A ativar',
        'generic_trial' => 'Teste',
        'trialing' => 'Em teste',
        'active' => 'Ativa',
        'past_due' => 'Pagamento falhado',
        'incomplete' => 'Pagamento incompleto',
        'incomplete_expired' => 'Pagamento expirado',
        'grace' => 'Termina no fim do período',
        'paused' => 'Pausada',
        'ended' => 'Terminada',
    ],

    'nav' => [
        'subscription' => 'Subscrição',
        'plan' => 'Plano',
        'payment_methods' => 'Métodos de pagamento',
        'invoices' => 'Faturas',
        'usage' => 'Utilização',
        'usage_history' => 'Histórico',
        'recovery' => 'Recuperação',
        'danger' => 'Zona de perigo',
        'group' => [
            'subscription' => 'Assinatura',
            'billing' => 'Faturação',
            'usage' => 'Utilização',
            'account' => 'Conta',
        ],
    ],

];
