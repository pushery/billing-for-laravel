<?php

declare(strict_types=1);

// A privacy-notice building block a consumer can adopt into their own policy. It is a suggestion with
// substance, not a form to be used verbatim and not legal advice: override it where your own wording or
// your own obligations differ.

return [
    'place_of_supply' => [
        'heading' => 'Determining the place of supply',
        'purpose' => 'When you place an order we determine which country the supply is taxed in, and we record that country so the tax return covering it can be prepared and later evidenced.',
        'legal_basis' => 'We do this to meet a legal obligation in tax law. It is not based on your consent and not on a balancing of interests, so there is nothing here for you to agree to or object to — the obligation applies to us regardless.',
        'data_categories' => 'We store the resulting two-letter country code. Where a network address is used to derive it, that address is discarded as soon as the country has been determined and is never stored, logged or passed on.',
        'retention' => 'The country code is kept for ten years, which is the period tax law requires this evidence to be available for.',
        'no_consent_note' => 'The determination happens on our server while your order is processed and is strictly necessary to complete it, so it does not require a cookie banner or any other consent step.',
    ],
];
