<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Entitlement grants per tier
    |--------------------------------------------------------------------------
    |
    | The licensing surface, deliberately separate from billing.php (pricing): what
    | each tier actually UNLOCKS. Two kinds of grant per tier —
    |
    |   - "features": boolean unlocks (does this tier get the feature at all).
    |   - "limits":   numeric ceilings (null means unlimited / not capped).
    |
    | An app resolves the owner's tier key (via its TierResolver) and asks the
    | License whether a feature is granted or what a limit is. A tier, feature or
    | limit that is not listed is denied / unlimited by the safe defaults below —
    | never an error.
    |
    */

    'tiers' => [

        // 'free' => [
        //     'features' => ['api' => false, 'priority_support' => false],
        //     'limits'   => ['projects' => 3, 'seats' => 1],
        // ],
        //
        // 'pro' => [
        //     'features' => ['api' => true, 'priority_support' => true],
        //     'limits'   => ['projects' => null, 'seats' => 10], // null = unlimited
        // ],

    ],

];
