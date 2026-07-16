<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by a consumer's membership event (a member joined, left, was removed) to name the team owner
 * whose seat count changed. The seat-sync listener reads it to know WHOSE seats to re-sync — the one thing
 * the package cannot infer, because it does not own the membership model.
 *
 * A consumer whose event cannot implement this interface (a third-party event) can instead register the
 * event and let the listener read a configured owner property; this interface is the explicit, unambiguous
 * path.
 */
interface AffectsSeats
{
    /** The team owner whose seats this event changed, or null when it does not concern seat billing. */
    public function seatOwner(): ?Model;
}
