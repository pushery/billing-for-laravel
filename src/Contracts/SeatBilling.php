<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\SeatDowngradeBelowOccupied;
use Pushery\Billing\Seats\SeatSync;

/**
 * The provider seam for seat-based billing: read the quantity the provider is currently billing, and change
 * it. Kept separate from {@see SubscriptionActions} (tier swaps, cancel/resume) because seat quantity is a
 * distinct axis — a team can change seats without ever changing tier — and because the neutral
 * {@see SeatSync} service is the only thing that drives it, delegating the
 * provider-specific call here rather than talking to a driver directly.
 *
 * How proration is applied is the driver's own strategy: Stripe books it natively; a credit-balance driver
 * computes it into the customer balance. Neither is the seat service's concern.
 */
interface SeatBilling
{
    /**
     * The seat quantity the provider is billing this owner right now, or null when there is nothing to read
     * (no active subscription, or a subscription with no identifiable seat item). Null is the signal to the
     * seat service that there is no seat billing to keep in sync — never a zero it would try to "correct".
     */
    public function currentSeatQuantity(Model $owner): ?int;

    /**
     * Set the billed seat quantity, prorating the change unless told otherwise.
     *
     * MUST refuse — loudly — to set a quantity below the owner's occupied seat count: billing below what is
     * occupied is a silent oversell. See {@see SeatDowngradeBelowOccupied}.
     */
    public function updateSeatQuantity(Model $owner, int $quantity, bool $prorate = true): void;
}
