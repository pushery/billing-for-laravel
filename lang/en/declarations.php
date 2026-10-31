<?php

declare(strict_types=1);

// The wording of a declaration made through the cancellation button or the withdrawal function, as the confirmation
// mail and a receipt page state it. A page of your own writes its confirmation buttons from `cancellation.button` and
// `withdrawal.button`, so the sentence that quotes a button quotes the label the person pressed.

return [
    'labels' => [
        'received' => 'Received',
        'kind' => 'Kind of cancellation',
        'reason' => 'Reason',
        'name' => 'Name',
        'email' => 'Email address',
        'contract' => 'Contract',
        'ends' => 'The contract should end',
        'confirmation_sent_to' => 'Confirmation sent to',
    ],
    'cancellation' => [
        'button' => 'Cancel now',
        'declared_through' => 'Declared by pressing the button “:button” on :app.',
        'kinds' => [
            'ordinary' => 'Ordinary cancellation',
            'extraordinary' => 'Extraordinary cancellation',
        ],
        'ends' => [
            'earliest_possible' => 'At the earliest possible date',
            'earliest_possible_on' => 'At the earliest possible date, which is :date',
        ],
    ],
    'withdrawal' => [
        'button' => 'Confirm withdrawal',
        'declared_through' => 'Declared by pressing the button “:button” on :app.',
    ],
    // Why a declaration waits for a person, after the colon of the review mail and of the admin console
    // (`DeclarationFailure`). Each is a clause, so none ends in a period.
    'failure' => [
        'term_unknown' => 'the end of the first term of the contract is not known, so its earliest end could not be worked out',
        'refund_refused' => 'the contract has ended, and the payment provider refused the refund the person is owed',
        'withdrawal_window_closed' => 'the withdrawal period had ended when the withdrawal arrived',
        'withdrawal_unavailable' => 'the subscription could not be withdrawn from automatically, for example because no running subscription was found or its tier gives consumers no right of withdrawal',
        'cancellation_unavailable' => 'the contract could not be ended automatically, because no running subscription was found or a payment for it is still being collected',
        'end_inside_period_is_final' => 'the subscription already ends at a moment inside its period, and that end cannot be moved',
        'other' => 'the payment provider or the application refused it',
    ],
    'contract_name' => ':tier subscription',
    'contract_unnamed' => 'Subscription',
];
