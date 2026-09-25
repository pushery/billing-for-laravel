<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Non è stato possibile elaborare il tuo pagamento',
        'intro' => 'Non siamo riusciti a elaborare il tuo ultimo pagamento.',
        'outro' => 'Aggiorna i tuoi dati di pagamento per mantenere attivo l’abbonamento.',
        'cta' => 'Aggiorna i dati di pagamento',
    ],

    'payment_succeeded' => [
        'subject' => 'La tua ricevuta di pagamento',
        'intro' => 'Grazie: abbiamo ricevuto il tuo pagamento.',
        'declarations' => 'Le tue dichiarazioni prima dell\'inizio della fornitura:',
        'outro' => 'Trovi una copia di questa ricevuta nel tuo storico di fatturazione.',
        'cta' => 'Vedi le ricevute',
    ],

    'trial_ending' => [
        'subject' => 'La tua prova termina a breve',
        'intro' => 'La tua prova gratuita sta per finire.',
        'outro' => 'Aggiungi un metodo di pagamento prima della fine così il tuo abbonamento continua senza interruzioni.',
        'cta' => 'Aggiungi un metodo di pagamento',
    ],

    'subscription_canceled' => [
        'subject' => 'Il tuo abbonamento è stato annullato',
        'intro' => 'Il tuo abbonamento è stato annullato e non verrà rinnovato.',
        'outro' => 'Mantieni l’accesso fino alla fine del periodo pagato, indicato di seguito.',
        'cta' => 'Vedi il piano',
    ],

    'tax_status_changed' => [
        'subject' => 'La tua posizione fiscale è cambiata',
        'intro' => 'La tua posizione fiscale è passata da :from a :to. Lo abbiamo stabilito dai nostri registri: non lo hai richiesto tu.',
        'effective' => 'Si applica dal :date.',
        'consequence' => 'Da quella data l\'importo che ricevi è più alto, perché l\'imposta viaggia con esso. Quanto ti resta non cambia.',
        'outro' => 'Se questo non corrisponde alla tua situazione, faccelo sapere: possiamo correggerlo.',
    ],

    'reattestation_due' => [
        'subject' => 'La tua attestazione fiscale va rinnovata',
        'intro_due' => 'È iniziato un nuovo anno, quindi la tua attestazione fiscale va rinnovata. Conferma la tua posizione fiscale entro il :date.',
        'intro_last' => 'La tua attestazione fiscale scade il :date e non abbiamo ancora ricevuto il rinnovo.',
        'consequence' => 'Se non viene rinnovata entro il :date, vendite e pagamenti si fermano da quel giorno finché non confermi di nuovo la tua posizione.',
        'cta' => 'Conferma la tua posizione fiscale',
        'outro' => 'Basta un momento, e se la tua posizione è rimasta la stessa per te non cambia nulla.',
    ],

    'seller_data_reminder' => [
        'subject' => 'Completa i tuoi dati di venditore',
        'intro_first' => 'Ci mancano ancora alcuni dati che ci servono da te come venditore: :fields.',
        'intro_second' => 'Secondo promemoria: ci mancano ancora alcuni dati che ci servono da te come venditore: :fields.',
        'consequence_withhold_payout' => 'Se non sono completi entro il :date, da quel giorno tratteniamo i tuoi pagamenti finché non lo saranno. Non si perde nulla: tutto ciò che viene trattenuto viene pagato non appena i tuoi dati sono completi.',
        'consequence_suspend_sales' => 'Se non sono completi entro il :date, da quel giorno le vendite vengono sospese finché non lo saranno.',
        'cta' => 'Completa i tuoi dati',
        'outro' => 'Ci vuole solo un momento.',
        'fields' => [
            'seller_name' => 'il tuo nome',
            'seller_address' => 'il tuo indirizzo',
            'payout_account' => 'il tuo conto per i pagamenti',
            'payout_account_holder' => 'l\'intestatario del tuo conto per i pagamenti',
            'seller_tax_identifier' => 'il tuo codice fiscale',
            'seller_register_number' => 'il tuo numero di iscrizione al registro delle imprese',
            'seller_date_of_birth' => 'la tua data di nascita',
            'seller_vat_identifier' => 'la tua partita IVA',
        ],
    ],

    'suspension_warning' => [
        'subject' => 'Azione necessaria: il tuo accesso verrà sospeso',
        'intro' => 'Il tuo account ha un saldo scaduto e l’accesso verrà sospeso a breve.',
        'outro' => 'Salda l’importo indicato di seguito per mantenere l’accesso.',
        'cta' => 'Salda l\'importo dovuto',
    ],

    'card_expiring' => [
        'subject' => 'La tua carta sta per scadere',
        'intro' => 'La carta salvata (:card) scade a breve.',
        'outro' => 'Aggiorna il tuo metodo di pagamento per evitare un’interruzione dell’abbonamento.',
        'cta' => 'Aggiorna la carta',
    ],

    'payment_method_removed' => [
        'subject' => 'Un metodo di pagamento è stato rimosso',
        'intro' => 'Un metodo di pagamento che poteva essere addebitato per il tuo abbonamento è stato rimosso dal tuo account.',
        'outro' => 'Se non sei stato tu, aggiungi un nuovo metodo di pagamento per mantenere attivo l’abbonamento.',
        'cta' => 'Gestisci i metodi di pagamento',
    ],

    'quota_warning' => [
        'subject' => 'Ti stai avvicinando al limite di :meter',
        'intro' => 'In questo periodo hai usato :used di :included :meter inclusi.',
        'outro' => 'Ricarica o passa a un piano superiore per continuare senza interruzioni.',
        'cta' => 'Vedi l\'utilizzo',
    ],

    'subscription_activated' => [
        'subject' => 'Il tuo abbonamento è attivo',
        'intro' => 'Il tuo piano :tier è ora attivo: tutto ciò che include è sbloccato.',
        'outro' => 'Puoi vedere o cambiare il piano quando vuoi nelle impostazioni di fatturazione.',
        'cta' => 'Vedi il piano',
    ],

    'payment_action_required' => [
        'subject' => 'Conferma il pagamento per continuare',
        'intro' => 'La tua banca ha bisogno che tu confermi questo pagamento prima che l’abbonamento possa continuare.',
        'outro' => 'Confermalo ora per evitare interruzioni del servizio.',
        'cta' => 'Conferma il pagamento',
    ],

];
