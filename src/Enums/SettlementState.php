<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a routed payment has actually arrived.
 *
 * The distinction that matters is `Pending` versus `Failed`, and it is not pedantry: a 3-D Secure step is
 * routine under PSD2, and a payment waiting on one has not failed. Collapsing the two would either credit
 * a merchant for money that never came or discard a payment that was about to succeed.
 *
 * Nothing is credited to a merchant before `Settled`. The earnings journal reads this, not the row's
 * existence — a routed charge exists from the moment it is attempted.
 */
enum SettlementState: string
{
    /** Attempted and not yet resolved — including a payment waiting on buyer authentication. */
    case Pending = 'pending';

    /** The money arrived and the merchant's share is real. */
    case Settled = 'settled';

    /** The payment will not complete. Terminal; a new attempt is a new charge. */
    case Failed = 'failed';

    /** Whether a merchant may be credited for this payment. */
    public function isCreditable(): bool
    {
        return $this === self::Settled;
    }
}
