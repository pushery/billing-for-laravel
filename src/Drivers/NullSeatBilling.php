<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SeatBilling;

/**
 * The seat seam for a driver that does not bill seats at a provider. It reports no seat billing to keep in
 * sync, so the seat service no-ops before it ever asks for a change. Unlike the usage seam — where a missing
 * capability is a revenue hole that must be surfaced loudly — a driver with no seat concept simply has no
 * seat quantity, so there is nothing to fail on.
 */
final readonly class NullSeatBilling implements SeatBilling
{
    public function currentSeatQuantity(Model $owner): ?int
    {
        return null;
    }

    public function updateSeatQuantity(Model $owner, int $quantity, bool $prorate = true): void
    {
        // No provider seat billing to update.
    }
}
