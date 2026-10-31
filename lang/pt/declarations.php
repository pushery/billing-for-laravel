<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Data de receção',
        'kind' => 'Tipo de cancelamento',
        'reason' => 'Motivo',
        'name' => 'Nome',
        'email' => 'Endereço de e-mail',
        'contract' => 'Contrato',
        'ends' => 'O contrato deve terminar',
        'confirmation_sent_to' => 'Confirmação enviada para',
    ],
    'cancellation' => [
        'button' => 'Cancelar agora',
        'declared_through' => 'Declarado ao premir o botão «:button» em :app.',
        'kinds' => [
            'ordinary' => 'Cancelamento ordinário',
            'extraordinary' => 'Cancelamento extraordinário',
        ],
        'ends' => [
            'earliest_possible' => 'Na data mais próxima possível',
            'earliest_possible_on' => 'Na data mais próxima possível, ou seja, a :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirmar a livre resolução',
        'declared_through' => 'Declarada ao premir o botão «:button» em :app.',
    ],
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'o fim do primeiro prazo do contrato não é conhecido, pelo que não foi possível calcular o seu fim mais próximo',
        'refund_refused' => 'o contrato terminou e o prestador de pagamentos recusou o reembolso devido à pessoa',
        'withdrawal_window_closed' => 'o prazo de livre resolução já tinha terminado quando a livre resolução chegou',
        'withdrawal_unavailable' => 'não foi possível exercer a livre resolução da subscrição automaticamente, por exemplo porque não foi encontrada nenhuma subscrição ativa ou o seu nível não dá aos consumidores direito de livre resolução',
        'cancellation_unavailable' => 'não foi possível terminar o contrato automaticamente, porque não foi encontrada nenhuma subscrição ativa ou um pagamento ainda está a ser cobrado',
        'end_inside_period_is_final' => 'a subscrição já termina num momento dentro do seu período, e esse fim não pode ser alterado',
        'other' => 'o prestador de pagamentos ou a aplicação recusou-a',
    ],
    'contract_name' => 'Subscrição :tier',
    'contract_unnamed' => 'Subscrição',
];
