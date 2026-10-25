<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/** Why the next period of a subscription paid from an internal balance was not taken. */
enum BalanceRenewalRefusal: string
{
    /** The buyer's balance did not cover the period. */
    case Shortfall = 'shortfall';

    /** The host declined the renewal, or binds no terms to sell it under. */
    case Declined = 'declined';

    /** The merchant no longer sells the tier, or the merchant or the buyer is gone. */
    case TierWithdrawn = 'tier_withdrawn';
}
