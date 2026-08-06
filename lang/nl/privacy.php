<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Bepaling van de plaats van de prestatie',
        'purpose' => 'Bij een bestelling bepalen we in welk land de prestatie belast is en leggen we dat land vast, zodat de bijbehorende belastingaangifte kan worden opgesteld en later onderbouwd.',
        'legal_basis' => 'We doen dit om aan een wettelijke fiscale verplichting te voldoen. Het berust niet op jouw toestemming en niet op een belangenafweging — er valt hier dus niets toe te stemmen en niets tegen in te brengen; de verplichting geldt hoe dan ook voor ons.',
        'data_categories' => 'We bewaren de landcode van twee letters. Wordt daarvoor een netwerkadres gebruikt, dan wordt dat adres weggegooid zodra het land bepaald is en het wordt niet opgeslagen, gelogd of doorgegeven.',
        'retention' => 'De landcode wordt tien jaar bewaard; zo lang eist het belastingrecht dat dit bewijs beschikbaar blijft.',
        'no_consent_note' => 'De bepaling gebeurt op onze server tijdens de afhandeling van de bestelling en is strikt noodzakelijk om die te voltooien; er is dus geen cookiebanner of andere toestemming voor nodig.',
    ],
];
