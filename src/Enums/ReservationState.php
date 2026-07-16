<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The life of one hold on a metered allowance: it is claimed (pending), and then it is either turned into
 * usage (committed) or handed back (released). A settled hold is settled forever — that is what makes
 * settling it idempotent, so a retried job cannot spend the same allowance twice or hand it back twice.
 */
enum ReservationState: string
{
    case Pending = 'pending';       // held: it counts against the ceiling, and it will expire if nobody settles it
    case Committed = 'committed';   // became usage
    case Released = 'released';     // handed back: the work never ran, or it expired

    /** Whether this hold has been settled and must never be settled again. */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
