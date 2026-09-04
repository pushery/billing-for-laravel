<?php

declare(strict_types=1);

return [

    'title' => 'Billing admin',
    'badge' => 'Admin',

    'metrics' => [
        'heading' => 'Metrics',
        'mrr' => 'MRR',
        'active' => 'Active subscriptions',
        'trials' => 'On trial',
        'dunning' => 'In dunning',
        'churned' => 'Churned (:days d)',
    ],

    'comp' => [
        'heading' => 'Comp a tier',
        'intro' => 'Grant an owner a tier out of band. Use a tier listed in billing.untouchable_tiers so the next provider webhook does not overwrite it.',
        'owner_id' => 'Owner ID',
        'tier' => 'Tier',
        'submit' => 'Grant tier',
        'granted' => 'Tier granted.',
        'not_found' => 'No owner found for that ID.',
        'invalid_tier' => 'That tier is not configured in billing.tiers.',
    ],

    'datev' => [
        'heading' => 'Booking batch (DATEV)',
        'intro' => 'Download a period as a DATEV EXTF booking batch — the file a German tax advisor imports. It is the same batch the scheduled export produces, nothing is stored on the server, and the download is refused rather than truncated if the period cannot be booked as one batch.',
        'from' => 'From',
        'to' => 'To',
        'submit' => 'Download batch',
        'invalid_period' => 'Give a start and an end date, with the end on or after the start.',
        'refused' => 'The batch was refused and nothing was downloaded:',
        'unbalanced' => 'This batch does not tie out and must not be filed.',
        'imbalance_figures' => 'The sub-ledger holds :subledger in merchant payables; the exported batch books :batch to the payables accounts, a difference of :difference.',
        'download_anyway' => 'Download it anyway',
    ],

    'cancel' => [
        'heading' => 'Cancel a subscription',
        'intro' => 'End an owner\'s subscription immediately. The change is recorded on the audit trail with your name against it.',
        'owner_id' => 'Owner ID',
        'submit' => 'Cancel subscription',
        'canceled' => 'Subscription canceled.',
        'not_found' => 'No owner found for that ID.',
    ],

    'audit' => [
        'heading' => 'Recent activity',
        'type' => 'Event',
        'source' => 'Source',
        'subject' => 'Subject',
        'when' => 'When',
        'empty' => 'No billing events recorded yet.',
    ],

    'source' => [
        'customer' => 'Customer',
        'admin' => 'Admin',
        'webhook' => 'Webhook',
        'system' => 'System',
    ],

];
