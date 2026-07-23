<?php

declare(strict_types=1);

return [

    'title' => 'Billing',
    'skip_to_content' => 'Skip to content',
    'logout' => 'Log out',

    'overview' => [
        'heading' => 'Billing overview',
        'current_plan' => 'Current plan: :plan.',
    ],

    'banner' => [
        'past_due' => 'Your last payment failed. Update your payment method to keep your subscription active.',
        'incomplete' => 'Your payment needs confirmation before your subscription starts.',
        'grace' => 'Your subscription is canceled and ends soon. Resume it to keep your access.',
        'paused' => 'Your billing is paused, so your paid features are on hold. Resume it whenever you like.',
        'trial_ending' => 'Your trial ends soon. Pick a plan to keep your access.',
        'cta' => [
            'recover' => 'Fix payment',
            'confirm' => 'Confirm payment',
            'resume' => 'Resume',
            'upgrade' => 'Choose a plan',
        ],
    ],

    'manage' => [
        'heading' => 'Change plan',
        'current' => 'Current plan: :plan.',
        'card_on_file' => 'Card on file: :brand ending :last4.',
        'addons_heading' => 'Add-ons',
        'addon_buy' => 'Buy',
        'swap_to' => 'Switch',
        'scheduled_swap' => 'Changes to :plan on :date.',
        'scheduled_swap_cancel' => 'Cancel scheduled change',
        'subscribe' => 'Subscribe',
        'trial_days' => 'Includes a :days-day free trial.',
        'preview' => 'Preview cost',
        'preview_due' => 'Due today with proration: :amount.',
        'preview_unavailable' => 'No estimate available for this change.',
        'no_options' => 'No other plans are available.',
        'link_out' => [
            'body' => 'Billing for this account is managed on our billing partner’s site.',
            'action' => 'Manage billing',
        ],
    ],

    'coupon' => [
        'label' => 'Coupon code',
        'placeholder' => 'Enter a code',
        'applied' => 'Coupon applied.',
        'invalid' => 'That code is not valid.',
    ],

    'trial' => [
        'generic' => 'Your free trial ends in :days day — subscribe to keep your access.|Your free trial ends in :days days — subscribe to keep your access.',
        'add_pm' => 'Your trial ends in :days day — add a payment method so your plan continues.|Your trial ends in :days days — add a payment method so your plan continues.',
        'upgrade' => 'Your trial ends in :days day — review your plan whenever you like.|Your trial ends in :days days — review your plan whenever you like.',
        'usage' => 'You have :days day left on your trial; the usage below is your trial plan.|You have :days days left on your trial; the usage below is your trial plan.',
        'cta' => [
            'subscribe' => 'Subscribe now',
            'add_payment_method' => 'Add payment method',
            'upgrade' => 'View plans',
        ],
    ],

    'interval' => [
        'day' => 'day',
        'week' => 'week',
        'month' => 'month',
        'year' => 'year',
    ],

    'cancel_survey' => [
        'prompt' => 'Reason for leaving (optional)',
        'no_reason' => 'Prefer not to say',
        'detail_label' => 'Tell us more',
        'detail_placeholder' => 'What made you cancel?',
        'detail_required' => 'Please add a detail for \'Other\'.',
        'reason' => [
            'too_expensive' => 'It is too expensive',
            'missing_features' => 'Missing features I need',
            'not_using_enough' => 'I am not using it enough',
            'switched_provider' => 'I switched to another provider',
            'technical_issues' => 'Technical issues',
            'no_longer_needed' => 'I no longer need it',
            'other' => 'Other',
        ],
    ],
    'subscription' => [
        'heading' => 'Subscription',
        'status' => 'Status',
        'next_invoice' => 'Next invoice: :amount on :date.',
        'access_ends' => 'Your access ends on :date.',
        'access_ended' => 'Your access ended on :date.',
        'cancel' => 'Cancel subscription',
        'resume' => 'Resume subscription',
        'portal' => 'Open the billing portal',
    ],

    'invoices' => [
        'heading' => 'Invoices',
        'empty' => 'No invoices yet.',
        'date' => 'Date',
        'number' => 'Number',
        'amount' => 'Amount',
        'status' => 'Status',
        'download' => 'Download',
        'load_older' => 'Load older',
    ],

    'invoice_status' => [
        'draft' => 'Draft',
        'open' => 'Open',
        'paid' => 'Paid',
        'uncollectible' => 'Uncollectible',
        'void' => 'Void',
        'refunded' => 'Refunded',
    ],

    'payment_methods' => [
        'heading' => 'Payment methods',
        'add' => 'Add payment method',
        'default' => 'Default',
        'make_default' => 'Make default',
        'remove' => 'Remove',
        'empty' => 'No payment methods on file.',
        'expired' => 'Expired :date',
        'expiring' => 'Expires :date',
        'cannot_remove_last_default' => 'You cannot remove the card your active subscription is billed to. Add another payment method and make it the default first.',
    ],

    'degraded' => 'Part of this page could not be loaded. Please try again in a moment.',

    'usage' => [
        'heading' => 'Usage',
        'unavailable' => 'Usage is temporarily unavailable. Please try again in a moment.',
        'prepaid' => 'Prepaid balance: :units :unit',
        'unmetered' => 'Your plan has no metered limits.',
        'warning' => 'You are approaching your limit.',
        'over' => 'You have exceeded your limit.',
        'over_soft' => 'You are over your included allowance; usage beyond it is billed.',
        'cta_upgrade' => 'Upgrade to raise this limit',
        'cta_topup' => 'Top up this allowance',
    ],

    'usage_history' => [
        'heading' => 'Usage history',
        'unavailable' => 'The usage history isn’t available right now. Please try again in a moment.',
        'empty' => 'No usage recorded yet.',
        'periods_heading' => 'Past periods',
        'used' => ':used used',
        'not_metered' => 'Not metered',
        'prepaid_used' => ':units prepaid',
        'topups_heading' => 'Top-ups',
        'reversed' => 'reversed',
    ],

    'recovery' => [
        'heading' => 'Payment recovery',
        'failed' => 'Your last payment failed.',
        'current_method' => 'The payment method on file is :method.',
        'no_method' => 'You have no payment method on file.',
        'update' => 'Update payment method',
        'all_good' => 'Nothing to recover — your payments are up to date.',
        'incomplete' => 'Your payment needs confirmation before your subscription starts.',
        'incomplete_hint' => 'Your bank asked you to confirm this payment. Confirm it to activate your subscription.',
        'confirm' => 'Confirm payment',
    ],

    'reconfirm' => [
        'prompt' => 'Confirm your password to continue.',
        'wrong' => 'That didn’t match. Please try again.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
    ],

    'danger' => [
        'heading' => 'Danger zone',
        'explanation' => 'Canceling now stops billing immediately, with no grace period.',
        'cancel_now' => 'Cancel billing now',
        'confirm_question' => 'This cannot be undone. Cancel billing immediately?',
        'confirm_yes' => 'Yes, cancel now',
        'confirm_no' => 'Keep my subscription',
    ],

    'credit' => [
        'balance' => 'You have :amount in account credit.',
        'explanation' => 'It is applied automatically to your next invoice.',
    ],

    'state' => [
        'none' => 'No subscription',
        'churned' => 'Not subscribed',
        'activating' => 'Activating',
        'generic_trial' => 'Trial',
        'trialing' => 'Trialing',
        'active' => 'Active',
        'past_due' => 'Payment failed',
        'incomplete' => 'Payment incomplete',
        'incomplete_expired' => 'Payment expired',
        'grace' => 'Cancels at period end',
        'paused' => 'Paused',
        'ended' => 'Ended',
    ],

    'nav' => [
        'subscription' => 'Subscription',
        'plan' => 'Plan',
        'payment_methods' => 'Payment methods',
        'invoices' => 'Invoices',
        'usage' => 'Usage',
        'usage_history' => 'History',
        'recovery' => 'Recovery',
        'danger' => 'Danger zone',
        'group' => [
            'subscription' => 'Subscription',
            'billing' => 'Billing',
            'usage' => 'Usage',
            'account' => 'Account',
        ],
    ],

];
