<?php

declare(strict_types=1);

return [

    'title' => 'Fatturazione',
    'skip_to_content' => 'Vai al contenuto',
    'logout' => 'Esci',

    'overview' => [
        'heading' => 'Panoramica della fatturazione',
        'current_plan' => 'Piano attuale: :plan.',
    ],

    'banner' => [
        'past_due' => 'Il tuo ultimo pagamento non è andato a buon fine. Aggiorna il tuo metodo di pagamento per mantenere attivo l’abbonamento.',
        'incomplete' => 'Il tuo pagamento deve essere confermato prima che l’abbonamento inizi.',
        'grace' => 'Il tuo abbonamento è stato annullato e termina a breve. Riprendilo per mantenere l’accesso.',
        'paused' => 'La tua fatturazione è in pausa, quindi le funzioni a pagamento sono sospese. Puoi riprenderla quando vuoi.',
        'trial_ending' => 'La tua prova termina a breve. Scegli un piano per mantenere l’accesso.',
        'cta' => [
            'recover' => 'Risolvi il pagamento',
            'confirm' => 'Conferma il pagamento',
            'resume' => 'Riprendi',
            'upgrade' => 'Scegli un piano',
        ],
    ],

    'manage' => [
        'heading' => 'Cambia piano',
        'current' => 'Piano attuale: :plan.',
        'card_on_file' => 'Carta salvata: :brand con finale :last4.',
        'addons_heading' => 'Componenti aggiuntivi',
        'addon_buy' => 'Acquista',
        'swap_to' => 'Cambia',
        'subscribe' => 'Abbonati',
        'trial_days' => 'Include una prova gratuita di :days giorni.',
        'preview' => 'Anteprima costo',
        'preview_due' => 'Da pagare oggi con conguaglio: :amount.',
        'preview_unavailable' => 'Nessuna stima disponibile per questa modifica.',
        'no_options' => 'Nessun altro piano disponibile.',
        'link_out' => [
            'body' => 'La fatturazione di questo account è gestita sul sito del nostro partner di fatturazione.',
            'action' => 'Gestisci la fatturazione',
        ],
    ],

    'coupon' => [
        'label' => 'Codice coupon',
        'placeholder' => 'Inserisci un codice',
        'applied' => 'Coupon applicato.',
        'invalid' => 'Questo codice non è valido.',
    ],

    'trial' => [
        'generic' => 'La tua prova gratuita termina tra :days giorno: scegli un piano per mantenere il tuo accesso.|La tua prova gratuita termina tra :days giorni: scegli un piano per mantenere il tuo accesso.',
        'add_pm' => 'La tua prova termina tra :days giorno: aggiungi un metodo di pagamento affinché il tuo piano continui.|La tua prova termina tra :days giorni: aggiungi un metodo di pagamento affinché il tuo piano continui.',
        'upgrade' => 'La tua prova termina tra :days giorno: puoi rivedere il tuo piano quando vuoi.|La tua prova termina tra :days giorni: puoi rivedere il tuo piano quando vuoi.',
        'usage' => 'Ti resta :days giorno di prova; l’utilizzo qui sotto è quello del tuo piano di prova.|Ti restano :days giorni di prova; l’utilizzo qui sotto è quello del tuo piano di prova.',
        'cta' => [
            'subscribe' => 'Abbonati ora',
            'add_payment_method' => 'Aggiungi un metodo di pagamento',
            'upgrade' => 'Vedi i piani',
        ],
    ],

    'interval' => [
        'day' => 'giorno',
        'week' => 'settimana',
        'month' => 'mese',
        'year' => 'anno',
    ],

    'subscription' => [
        'heading' => 'Abbonamento',
        'status' => 'Stato',
        'next_invoice' => 'Prossima fattura: :amount il :date.',
        'access_ends' => 'Il tuo accesso termina il :date.',
        'access_ended' => 'Il tuo accesso è terminato il :date.',
        'cancel' => 'Annulla abbonamento',
        'resume' => 'Riprendi abbonamento',
        'portal' => 'Apri il portale di fatturazione',
    ],

    'invoices' => [
        'heading' => 'Fatture',
        'empty' => 'Ancora nessuna fattura.',
        'date' => 'Data',
        'number' => 'Numero',
        'amount' => 'Importo',
        'status' => 'Stato',
        'download' => 'Scarica',
        'load_older' => 'Carica meno recenti',
    ],

    'invoice_status' => [
        'draft' => 'Bozza',
        'open' => 'Aperta',
        'paid' => 'Pagata',
        'uncollectible' => 'Inesigibile',
        'void' => 'Annullata',
        'refunded' => 'Rimborsata',
    ],

    'payment_methods' => [
        'heading' => 'Metodi di pagamento',
        'add' => 'Aggiungi metodo di pagamento',
        'default' => 'Predefinito',
        'make_default' => 'Imposta come predefinito',
        'remove' => 'Rimuovi',
        'empty' => 'Nessun metodo di pagamento salvato.',
        'expired' => 'Scaduta :date',
        'expiring' => 'Scade :date',
    ],

    'degraded' => 'Non è stato possibile caricare una parte di questa pagina. Riprova tra un momento.',

    'usage' => [
        'unavailable' => 'L’utilizzo è temporaneamente non disponibile. Riprova tra un momento.',
        'heading' => 'Utilizzo',
        'prepaid' => 'Saldo prepagato: :units :unit',
        'unmetered' => 'Il tuo piano non prevede limiti di consumo.',
        'warning' => 'Ti stai avvicinando al tuo limite.',
        'over' => 'Hai superato il tuo limite.',
        'over_soft' => 'Hai superato la quota inclusa; l’utilizzo eccedente viene fatturato.',
        'cta_upgrade' => 'Fai l’upgrade per aumentare questo limite',
        'cta_topup' => 'Ricarica questa quota',
    ],

    'usage_history' => [
        'heading' => 'Cronologia utilizzo',
        'unavailable' => 'La cronologia dell’utilizzo non è disponibile al momento. Riprova tra un istante.',
        'empty' => 'Nessun utilizzo registrato finora.',
        'periods_heading' => 'Periodi precedenti',
        'used' => ':used utilizzati',
        'not_metered' => 'Non misurato',
        'prepaid_used' => ':units prepagati',
        'topups_heading' => 'Ricariche',
        'reversed' => 'annullato',
    ],

    'recovery' => [
        'heading' => 'Recupero del pagamento',
        'failed' => 'Il tuo ultimo pagamento non è andato a buon fine.',
        'current_method' => 'Il metodo di pagamento salvato è :method.',
        'no_method' => 'Non hai alcun metodo di pagamento salvato.',
        'update' => 'Aggiorna metodo di pagamento',
        'all_good' => 'Niente da recuperare: i tuoi pagamenti sono in regola.',
        'incomplete' => 'Il tuo pagamento deve essere confermato prima che l’abbonamento inizi.',
        'incomplete_hint' => 'La tua banca ti ha chiesto di confermare questo pagamento. Confermalo per attivare l’abbonamento.',
        'confirm' => 'Conferma il pagamento',
    ],

    'reconfirm' => [
        'prompt' => 'Conferma la tua password per continuare.',
        'wrong' => 'Non corrisponde. Riprova.',
        'throttled' => 'Troppi tentativi. Riprova tra :seconds secondi.',
    ],

    'danger' => [
        'heading' => 'Zona pericolosa',
        'explanation' => 'Se annulli ora, la fatturazione si interrompe immediatamente, senza periodo di tolleranza.',
        'cancel_now' => 'Interrompi la fatturazione ora',
        'confirm_question' => 'L’azione non è reversibile. Interrompere la fatturazione immediatamente?',
        'confirm_yes' => 'Sì, interrompi ora',
        'confirm_no' => 'Mantieni il mio abbonamento',
    ],

    'credit' => [
        'balance' => 'Hai :amount di credito sul tuo account.',
        'explanation' => 'Viene applicato automaticamente alla tua prossima fattura.',
    ],

    'state' => [
        'none' => 'Nessun abbonamento',
        'churned' => 'Non abbonato',
        'activating' => 'Attivazione in corso',
        'generic_trial' => 'Prova',
        'trialing' => 'In prova',
        'active' => 'Attivo',
        'past_due' => 'Pagamento non riuscito',
        'incomplete' => 'Pagamento incompleto',
        'incomplete_expired' => 'Pagamento scaduto',
        'grace' => 'Termina alla fine del periodo',
        'paused' => 'In pausa',
        'ended' => 'Terminato',
    ],

    'nav' => [
        'subscription' => 'Abbonamento',
        'plan' => 'Piano',
        'payment_methods' => 'Metodi di pagamento',
        'invoices' => 'Fatture',
        'usage' => 'Utilizzo',
        'usage_history' => 'Cronologia',
        'recovery' => 'Recupero',
        'danger' => 'Zona pericolosa',
        'group' => [
            'subscription' => 'Abbonamento',
            'billing' => 'Fatturazione',
            'usage' => 'Utilizzo',
            'account' => 'Account',
        ],
    ],

];
