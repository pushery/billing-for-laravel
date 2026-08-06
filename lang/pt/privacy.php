<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Determinação do lugar da prestação',
        'purpose' => 'Ao fazeres uma encomenda determinamos em que país a prestação é tributada e registamos esse país, para que a declaração fiscal correspondente possa ser elaborada e mais tarde comprovada.',
        'legal_basis' => 'Fazemo-lo para cumprir uma obrigação legal em matéria fiscal. Não assenta no teu consentimento nem numa ponderação de interesses, pelo que não há aqui nada a aceitar nem a que se opor: a obrigação recai sobre nós de qualquer forma.',
        'data_categories' => 'Guardamos o código de país de duas letras. Quando é usado um endereço de rede para o determinar, esse endereço é descartado assim que o país fica apurado e não é armazenado, registado nem transmitido.',
        'retention' => 'O código de país é conservado dez anos, o prazo durante o qual a legislação fiscal exige que esta prova esteja disponível.',
        'no_consent_note' => 'A determinação ocorre no nosso servidor durante o processamento da encomenda e é estritamente necessária para a concluir, pelo que não exige aviso de cookies nem qualquer outro consentimento.',
    ],
];
