<?php

declare(strict_types=1);

return [

    'payment_failed' => [
        'subject' => 'Your payment could not be processed',
        'intro' => 'We were unable to process your latest payment.',
        'outro' => 'Please update your payment details to keep your subscription active.',
    ],

    'payment_succeeded' => [
        'subject' => 'Your payment receipt',
        'intro' => 'Thank you — your payment was received.',
        'outro' => 'A copy of this receipt is available in your billing history.',
    ],

    'trial_ending' => [
        'subject' => 'Your trial is ending soon',
        'intro' => 'Your free trial is coming to an end.',
        'outro' => 'Add a payment method before it ends to keep your subscription without interruption.',
    ],

    'subscription_canceled' => [
        'subject' => 'Your subscription has been canceled',
        'intro' => 'Your subscription has been canceled and will not renew.',
        'outro' => 'You keep access until the end of the paid period, shown below.',
    ],

    'suspension_warning' => [
        'subject' => 'Action needed: your access will be suspended',
        'intro' => 'Your account has an overdue balance and access will soon be suspended.',
        'outro' => 'Settle the amount below to keep your access.',
    ],

    'card_expiring' => [
        'subject' => 'Your card is about to expire',
        'intro' => 'The card on file (:card) is expiring soon.',
        'outro' => 'Update your payment method to avoid an interruption to your subscription.',
    ],

    'payment_method_removed' => [
        'subject' => 'A payment method was removed',
        'intro' => 'A payment method that could be charged for your subscription was removed from your account.',
        'outro' => 'If this was not you, add a new payment method to keep your subscription active.',
    ],

    'quota_warning' => [
        'subject' => 'You are close to your :meter limit',
        'intro' => 'You have used :used of your :included included :meter this period.',
        'outro' => 'Top up or upgrade to keep going without interruption.',
    ],

    'subscription_activated' => [
        'subject' => 'Your subscription is active',
        'intro' => 'Your :tier plan is now active — everything it includes is switched on.',
        'outro' => 'You can review or change your plan any time in your billing settings.',
    ],

    'payment_action_required' => [
        'subject' => 'Confirm your payment to continue',
        'intro' => 'Your bank needs you to confirm this payment before your subscription can continue.',
        'outro' => 'Confirm it now to avoid any interruption to your service.',
    ],

];
