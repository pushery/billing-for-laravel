<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Your payment could not be processed',
        'intro' => 'We were unable to process your latest payment.',
        'outro' => 'Please update your payment details to keep your subscription active.',
        'cta' => 'Update your payment details',
    ],

    'payment_succeeded' => [
        'subject' => 'Your payment receipt',
        'intro' => 'Thank you — your payment was received.',
        'declarations' => 'Your declarations before provision began:',
        'outro' => 'A copy of this receipt is available in your billing history.',
        'cta' => 'View your receipts',
    ],

    'trial_ending' => [
        'subject' => 'Your trial is ending soon',
        'intro' => 'Your free trial is coming to an end.',
        'outro' => 'Add a payment method before it ends to keep your subscription without interruption.',
        'cta' => 'Add a payment method',
    ],

    'subscription_canceled' => [
        'subject' => 'Your subscription has been canceled',
        'intro' => 'Your subscription has been canceled and will not renew.',
        'outro' => 'You keep access until the end of the paid period, shown below.',
        'cta' => 'View your plan',
    ],

    'tax_status_changed' => [
        'subject' => 'Your tax standing has changed',
        'intro' => 'Your tax standing changed from :from to :to. We made this change from our own records — you did not ask for it.',
        'effective' => 'It applies from :date.',
        'consequence' => 'From that date the amount that reaches you is larger, because tax now travels with it. The share you keep is unchanged.',
        'outro' => 'If this does not match your situation, tell us — we can correct it.',
    ],

    'suspension_warning' => [
        'subject' => 'Action needed: your access will be suspended',
        'intro' => 'Your account has an overdue balance and access will soon be suspended.',
        'outro' => 'Settle the amount below to keep your access.',
        'cta' => 'Settle what is owed',
    ],

    'card_expiring' => [
        'subject' => 'Your card is about to expire',
        'intro' => 'The card on file (:card) is expiring soon.',
        'outro' => 'Update your payment method to avoid an interruption to your subscription.',
        'cta' => 'Update your card',
    ],

    'payment_method_removed' => [
        'subject' => 'A payment method was removed',
        'intro' => 'A payment method that could be charged for your subscription was removed from your account.',
        'outro' => 'If this was not you, add a new payment method to keep your subscription active.',
        'cta' => 'Manage payment methods',
    ],

    'quota_warning' => [
        'subject' => 'You are close to your :meter limit',
        'intro' => 'You have used :used of your :included included :meter this period.',
        'outro' => 'Top up or upgrade to keep going without interruption.',
        'cta' => 'View your usage',
    ],

    'subscription_activated' => [
        'subject' => 'Your subscription is active',
        'intro' => 'Your :tier plan is now active — everything it includes is switched on.',
        'outro' => 'You can review or change your plan any time in your billing settings.',
        'cta' => 'View your plan',
    ],

    'payment_action_required' => [
        'subject' => 'Confirm your payment to continue',
        'intro' => 'Your bank needs you to confirm this payment before your subscription can continue.',
        'outro' => 'Confirm it now to avoid any interruption to your service.',
        'cta' => 'Confirm your payment',
    ],

];
