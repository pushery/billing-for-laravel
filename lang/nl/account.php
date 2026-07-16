<?php

declare(strict_types=1);

return [

    'title' => 'Facturering',
    'skip_to_content' => 'Naar de inhoud',
    'logout' => 'Uitloggen',

    'overview' => [
        'heading' => 'Factureringsoverzicht',
        'current_plan' => 'Huidig plan: :plan.',
    ],

    'banner' => [
        'past_due' => 'Je laatste betaling is mislukt. Werk je betaalmethode bij om je abonnement actief te houden.',
        'incomplete' => 'Je betaling moet worden bevestigd voordat je abonnement start.',
        'grace' => 'Je abonnement is opgezegd en eindigt binnenkort. Hervat het om je toegang te behouden.',
        'paused' => 'Je facturering staat op pauze, dus je betaalde functies liggen stil. Je kunt hem hervatten wanneer je wilt.',
        'trial_ending' => 'Je proefperiode eindigt binnenkort. Kies een plan om je toegang te behouden.',
        'cta' => [
            'recover' => 'Betaling herstellen',
            'confirm' => 'Betaling bevestigen',
            'resume' => 'Hervatten',
            'upgrade' => 'Kies een plan',
        ],
    ],

    'manage' => [
        'heading' => 'Plan wijzigen',
        'current' => 'Huidig plan: :plan.',
        'card_on_file' => 'Opgeslagen kaart: :brand eindigend op :last4.',
        'addons_heading' => 'Add-ons',
        'addon_buy' => 'Kopen',
        'swap_to' => 'Wisselen',
        'subscribe' => 'Abonneren',
        'trial_days' => 'Inclusief een gratis proefperiode van :days dagen.',
        'preview' => 'Kosten bekijken',
        'preview_due' => 'Vandaag te betalen met verrekening: :amount.',
        'preview_unavailable' => 'Geen schatting beschikbaar voor deze wijziging.',
        'no_options' => 'Er zijn geen andere plannen beschikbaar.',
        'link_out' => [
            'body' => 'De facturering van dit account wordt beheerd op de site van onze factureringspartner.',
            'action' => 'Facturering beheren',
        ],
    ],

    'coupon' => [
        'label' => 'Kortingscode',
        'placeholder' => 'Voer een code in',
        'applied' => 'Kortingscode toegepast.',
        'invalid' => 'Deze code is niet geldig.',
    ],

    'trial' => [
        'generic' => 'Je gratis proefperiode eindigt over :days dag — kies een plan om je toegang te behouden.|Je gratis proefperiode eindigt over :days dagen — kies een plan om je toegang te behouden.',
        'add_pm' => 'Je proefperiode eindigt over :days dag — voeg een betaalmethode toe zodat je plan doorloopt.|Je proefperiode eindigt over :days dagen — voeg een betaalmethode toe zodat je plan doorloopt.',
        'upgrade' => 'Je proefperiode eindigt over :days dag — je kunt je plan altijd aanpassen.|Je proefperiode eindigt over :days dagen — je kunt je plan altijd aanpassen.',
        'usage' => 'Je hebt nog :days dag proefperiode; het verbruik hieronder is dat van je proefplan.|Je hebt nog :days dagen proefperiode; het verbruik hieronder is dat van je proefplan.',
        'cta' => [
            'subscribe' => 'Nu abonneren',
            'add_payment_method' => 'Betaalmethode toevoegen',
            'upgrade' => 'Plannen bekijken',
        ],
    ],

    'interval' => [
        'day' => 'dag',
        'week' => 'week',
        'month' => 'maand',
        'year' => 'jaar',
    ],

    'subscription' => [
        'heading' => 'Abonnement',
        'status' => 'Status',
        'next_invoice' => 'Volgende factuur: :amount op :date.',
        'access_ends' => 'Je toegang eindigt op :date.',
        'access_ended' => 'Je toegang is geëindigd op :date.',
        'cancel' => 'Abonnement opzeggen',
        'resume' => 'Abonnement hervatten',
        'portal' => 'Factureringsportaal openen',
    ],

    'invoices' => [
        'heading' => 'Facturen',
        'empty' => 'Nog geen facturen.',
        'date' => 'Datum',
        'number' => 'Nummer',
        'amount' => 'Bedrag',
        'status' => 'Status',
        'download' => 'Downloaden',
        'load_older' => 'Oudere laden',
    ],

    'invoice_status' => [
        'draft' => 'Concept',
        'open' => 'Openstaand',
        'paid' => 'Betaald',
        'uncollectible' => 'Oninbaar',
        'void' => 'Geannuleerd',
        'refunded' => 'Terugbetaald',
    ],

    'payment_methods' => [
        'heading' => 'Betaalmethoden',
        'add' => 'Betaalmethode toevoegen',
        'default' => 'Standaard',
        'make_default' => 'Als standaard instellen',
        'remove' => 'Verwijderen',
        'empty' => 'Geen betaalmethoden opgeslagen.',
        'expired' => 'Verlopen :date',
        'expiring' => 'Verloopt :date',
    ],

    'degraded' => 'Een deel van deze pagina kon niet worden geladen. Probeer het zo opnieuw.',

    'usage' => [
        'unavailable' => 'Het verbruik is tijdelijk niet beschikbaar. Probeer het zo opnieuw.',
        'heading' => 'Verbruik',
        'prepaid' => 'Vooruitbetaald tegoed: :units :unit',
        'unmetered' => 'Je plan heeft geen gemeten limieten.',
        'warning' => 'Je nadert je limiet.',
        'over' => 'Je hebt je limiet overschreden.',
        'over_soft' => 'Je zit boven je inbegrepen tegoed; verbruik daarboven wordt in rekening gebracht.',
        'cta_upgrade' => 'Upgrade om deze limiet te verhogen',
        'cta_topup' => 'Vul dit tegoed aan',
    ],

    'usage_history' => [
        'heading' => 'Verbruiksgeschiedenis',
        'unavailable' => 'De verbruiksgeschiedenis is op dit moment niet beschikbaar. Probeer het zo meteen opnieuw.',
        'empty' => 'Nog geen verbruik geregistreerd.',
        'periods_heading' => 'Voorgaande periodes',
        'used' => ':used gebruikt',
        'not_metered' => 'Niet gemeten',
        'prepaid_used' => ':units vooruitbetaald',
        'topups_heading' => 'Aankopen',
        'reversed' => 'teruggedraaid',
    ],

    'recovery' => [
        'heading' => 'Betalingsherstel',
        'failed' => 'Je laatste betaling is mislukt.',
        'current_method' => 'De opgeslagen betaalmethode is :method.',
        'no_method' => 'Je hebt geen betaalmethode opgeslagen.',
        'update' => 'Betaalmethode bijwerken',
        'all_good' => 'Niets te herstellen — al je betalingen zijn voldaan.',
        'incomplete' => 'Je betaling moet worden bevestigd voordat je abonnement start.',
        'incomplete_hint' => 'Je bank heeft je gevraagd deze betaling te bevestigen. Bevestig deze om je abonnement te activeren.',
        'confirm' => 'Betaling bevestigen',
    ],

    'reconfirm' => [
        'prompt' => 'Bevestig je wachtwoord om door te gaan.',
        'wrong' => 'Dat klopt niet. Probeer het opnieuw.',
        'throttled' => 'Te veel pogingen. Probeer het over :seconds seconden opnieuw.',
    ],

    'danger' => [
        'heading' => 'Gevarenzone',
        'explanation' => 'Nu opzeggen stopt de facturering onmiddellijk, zonder respijtperiode.',
        'cancel_now' => 'Facturering nu stopzetten',
        'confirm_question' => 'Dit kan niet ongedaan worden gemaakt. Facturering onmiddellijk stopzetten?',
        'confirm_yes' => 'Ja, nu stopzetten',
        'confirm_no' => 'Mijn abonnement behouden',
    ],

    'credit' => [
        'balance' => 'Je hebt :amount tegoed op je account.',
        'explanation' => 'Het wordt automatisch verrekend met je volgende factuur.',
    ],

    'state' => [
        'none' => 'Geen abonnement',
        'churned' => 'Niet geabonneerd',
        'activating' => 'Wordt geactiveerd',
        'generic_trial' => 'Proef',
        'trialing' => 'In proefperiode',
        'active' => 'Actief',
        'past_due' => 'Betaling mislukt',
        'incomplete' => 'Betaling onvolledig',
        'incomplete_expired' => 'Betaling verlopen',
        'grace' => 'Eindigt aan het einde van de periode',
        'paused' => 'Gepauzeerd',
        'ended' => 'Beëindigd',
    ],

    'nav' => [
        'subscription' => 'Abonnement',
        'plan' => 'Plan',
        'payment_methods' => 'Betaalmethoden',
        'invoices' => 'Facturen',
        'usage' => 'Verbruik',
        'usage_history' => 'Geschiedenis',
        'recovery' => 'Herstel',
        'danger' => 'Gevarenzone',
        'group' => [
            'subscription' => 'Abonnement',
            'billing' => 'Facturering',
            'usage' => 'Gebruik',
            'account' => 'Account',
        ],
    ],

];
