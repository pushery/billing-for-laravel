<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Non è stato possibile elaborare il tuo pagamento',
        'intro' => 'Non siamo riusciti a elaborare il tuo ultimo pagamento.',
        'outro' => 'Aggiorna i tuoi dati di pagamento per mantenere attivo l’abbonamento.',
    ],

    'payment_succeeded' => [
        'subject' => 'La tua ricevuta di pagamento',
        'intro' => 'Grazie: abbiamo ricevuto il tuo pagamento.',
        'outro' => 'Trovi una copia di questa ricevuta nel tuo storico di fatturazione.',
    ],

    'trial_ending' => [
        'subject' => 'La tua prova termina a breve',
        'intro' => 'La tua prova gratuita sta per finire.',
        'outro' => 'Aggiungi un metodo di pagamento prima della fine così il tuo abbonamento continua senza interruzioni.',
    ],

    'subscription_canceled' => [
        'subject' => 'Il tuo abbonamento è stato annullato',
        'intro' => 'Il tuo abbonamento è stato annullato e non verrà rinnovato.',
        'outro' => 'Mantieni l’accesso fino alla fine del periodo pagato, indicato di seguito.',
    ],

    'suspension_warning' => [
        'subject' => 'Azione necessaria: il tuo accesso verrà sospeso',
        'intro' => 'Il tuo account ha un saldo scaduto e l’accesso verrà sospeso a breve.',
        'outro' => 'Salda l’importo indicato di seguito per mantenere l’accesso.',
    ],

    'card_expiring' => [
        'subject' => 'La tua carta sta per scadere',
        'intro' => 'La carta salvata (:card) scade a breve.',
        'outro' => 'Aggiorna il tuo metodo di pagamento per evitare un’interruzione dell’abbonamento.',
    ],

    'payment_method_removed' => [
        'subject' => 'Un metodo di pagamento è stato rimosso',
        'intro' => 'Un metodo di pagamento che poteva essere addebitato per il tuo abbonamento è stato rimosso dal tuo account.',
        'outro' => 'Se non sei stato tu, aggiungi un nuovo metodo di pagamento per mantenere attivo l’abbonamento.',
    ],

    'quota_warning' => [
        'subject' => 'Ti stai avvicinando al limite di :meter',
        'intro' => 'In questo periodo hai usato :used di :included :meter inclusi.',
        'outro' => 'Ricarica o passa a un piano superiore per continuare senza interruzioni.',
    ],

    'subscription_activated' => [
        'subject' => 'Il tuo abbonamento è attivo',
        'intro' => 'Il tuo piano :tier è ora attivo: tutto ciò che include è sbloccato.',
        'outro' => 'Puoi vedere o cambiare il piano quando vuoi nelle impostazioni di fatturazione.',
    ],

    'payment_action_required' => [
        'subject' => 'Conferma il pagamento per continuare',
        'intro' => 'La tua banca ha bisogno che tu confermi questo pagamento prima che l’abbonamento possa continuare.',
        'outro' => 'Confermalo ora per evitare interruzioni del servizio.',
    ],

];
