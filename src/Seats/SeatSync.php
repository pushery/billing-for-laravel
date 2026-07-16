<?php

declare(strict_types=1);

namespace Pushery\Billing\Seats;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Contracts\ProvidesSeats;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Events\SeatQuantityChanged;

/**
 * Keeps the seat quantity a team is billed for in step with the seats it actually occupies.
 *
 * This is provider-neutral on purpose: it decides WHETHER a change is needed and delegates the actual
 * provider call to {@see SeatBilling}. That split is what lets one service serve every driver, and it is
 * why a membership change — a member joining, leaving, being removed — can safely re-sync seats without the
 * caller knowing anything about the billing provider.
 *
 * It does nothing unless it must: not a seat owner, no seat billing at the provider, or already in sync — all
 * no-ops. Only a genuine drift reaches the provider, and only then is {@see SeatQuantityChanged} fired. That
 * matters because this runs on every membership change: an owner whose seat count did not move must not pay
 * the cost of a needless provider write, nor have an event claim a change that did not happen.
 */
final readonly class SeatSync
{
    public function __construct(private SeatBilling $billing) {}

    public function sync(Model $owner): void
    {
        if (! $owner instanceof ProvidesSeats) {
            return; // a personal owner, or any model that does not bill by seats
        }

        $current = $this->billing->currentSeatQuantity($owner);

        if ($current === null) {
            return; // no active seat subscription to keep in sync — nothing to correct
        }

        $target = $owner->seatCount();

        if ($current === $target) {
            return; // already correct — idempotent, no provider write, no event
        }

        $this->billing->updateSeatQuantity($owner, $target);

        Event::dispatch(new SeatQuantityChanged($owner, $current, $target));
    }
}
